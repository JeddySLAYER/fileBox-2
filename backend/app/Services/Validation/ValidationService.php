<?php

namespace App\Services\Validation;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Validation;
use App\Models\Workflow;
use App\Notifications\ValidationActionNotification;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ValidationService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Associe un workflow au document et crée les validations (une par étape).
     */
    public function start(Document $document, Workflow $workflow): Document
    {
        if (! $workflow->is_active) {
            throw ValidationException::withMessages([
                'workflow' => ['Ce workflow est inactif.'],
            ]);
        }

        $workflow->load('steps');

        if ($workflow->steps->isEmpty()) {
            throw ValidationException::withMessages([
                'workflow' => ['Le workflow ne contient aucune étape.'],
            ]);
        }

        if ($document->status === DocumentStatus::Archived) {
            throw ValidationException::withMessages([
                'document' => ['Un document archivé ne peut pas entrer en validation.'],
            ]);
        }

        if ($document->status === DocumentStatus::InValidation) {
            throw ValidationException::withMessages([
                'document' => ['Ce document est déjà en cours de validation.'],
            ]);
        }

        return DB::transaction(function () use ($document, $workflow) {
            $document->validations()->delete();

            $document->workflow_id = $workflow->id;
            $document->status = DocumentStatus::InValidation;
            $document->save();

            $firstValidation = null;

            foreach ($workflow->steps as $step) {
                $validation = Validation::query()->create([
                    'document_id' => $document->id,
                    'workflow_step_id' => $step->id,
                    'status' => ValidationStatus::Pending,
                ]);

                $firstValidation ??= $validation;
            }

            $loaded = $this->loadDocument($document);

            if ($firstValidation) {
                $this->notifyStakeholders($loaded, $firstValidation, 'started', excludeUserId: null);
            }

            $this->activityLog->log(
                action: 'validation.started',
                subject: $loaded,
                description: "Workflow démarré sur {$loaded->reference}",
                properties: ['workflow_id' => $workflow->id],
            );

            return $loaded;
        });
    }

    public function listForDocument(Document $document): Collection
    {
        return Validation::query()
            ->where('document_id', $document->id)
            ->with(['workflowStep.responsibleRole', 'workflowStep.responsibleUser', 'user'])
            ->get()
            ->sortBy(fn (Validation $v) => $v->workflowStep?->step_order ?? 0)
            ->values();
    }

    public function approve(Validation $validation, User $actor, ?string $comment = null): Document
    {
        $this->assertCanAct($validation, $actor);
        $this->assertIsCurrentStep($validation);

        return DB::transaction(function () use ($validation, $actor, $comment) {
            $validation->status = ValidationStatus::Approved;
            $validation->user_id = $actor->id;
            $validation->comment = $comment;
            $validation->validated_at = now();
            $validation->save();

            $document = $validation->document()->firstOrFail();

            if ($this->allMandatoryApproved($document)) {
                $document->status = DocumentStatus::Validated;
                $document->save();
            }

            $loaded = $this->loadDocument($document);
            $this->notifyStakeholders($loaded, $validation, 'approved', $actor->id);

            $this->activityLog->log(
                action: 'validation.approved',
                user: $actor,
                subject: $loaded,
                description: "Étape approuvée sur {$loaded->reference}",
            );

            return $loaded;
        });
    }

    public function reject(Validation $validation, User $actor, ?string $comment = null): Document
    {
        $this->assertCanAct($validation, $actor);
        $this->assertIsCurrentStep($validation);

        return DB::transaction(function () use ($validation, $actor, $comment) {
            $validation->status = ValidationStatus::Rejected;
            $validation->user_id = $actor->id;
            $validation->comment = $comment;
            $validation->validated_at = now();
            $validation->save();

            $document = $validation->document()->firstOrFail();
            $document->status = DocumentStatus::Rejected;
            $document->save();

            $loaded = $this->loadDocument($document);
            $this->notifyStakeholders($loaded, $validation, 'rejected', $actor->id);

            $this->activityLog->log(
                action: 'validation.rejected',
                user: $actor,
                subject: $loaded,
                description: "Document rejeté : {$loaded->reference}",
            );

            return $loaded;
        });
    }

    public function requestCorrection(Validation $validation, User $actor, ?string $comment = null): Document
    {
        $this->assertCanAct($validation, $actor);
        $this->assertIsCurrentStep($validation);

        return DB::transaction(function () use ($validation, $actor, $comment) {
            $validation->status = ValidationStatus::CorrectionRequested;
            $validation->user_id = $actor->id;
            $validation->comment = $comment;
            $validation->validated_at = now();
            $validation->save();

            $document = $validation->document()->firstOrFail();
            $document->status = DocumentStatus::Draft;
            $document->save();

            $loaded = $this->loadDocument($document);
            $this->notifyStakeholders($loaded, $validation, 'correction_requested', $actor->id);

            $this->activityLog->log(
                action: 'validation.correction_requested',
                user: $actor,
                subject: $loaded,
                description: "Correction demandée sur {$loaded->reference}",
            );

            return $loaded;
        });
    }

    /**
     * Relance le workflow après correction (réinitialise les validations).
     */
    public function restart(Document $document): Document
    {
        if (! $document->workflow_id) {
            throw ValidationException::withMessages([
                'document' => ['Aucun workflow associé à ce document.'],
            ]);
        }

        $workflow = Workflow::query()->findOrFail($document->workflow_id);

        // Remet le document hors "en_validation" pour permettre start()
        if ($document->status === DocumentStatus::InValidation) {
            throw ValidationException::withMessages([
                'document' => ['Le document est déjà en validation.'],
            ]);
        }

        $document->status = DocumentStatus::Draft;
        $document->save();

        return $this->start($document, $workflow);
    }

    public function currentPending(Document $document): ?Validation
    {
        $validations = $this->listForDocument($document);

        return $validations->first(
            fn (Validation $v) => $v->status === ValidationStatus::Pending
                && ($v->workflowStep?->is_mandatory ?? true)
        ) ?? $validations->first(
            fn (Validation $v) => $v->status === ValidationStatus::Pending
        );
    }

    private function assertCanAct(Validation $validation, User $actor): void
    {
        $document = $validation->document()->firstOrFail();

        if ($document->status !== DocumentStatus::InValidation) {
            throw ValidationException::withMessages([
                'validation' => ['Ce document n\'est pas en cours de validation.'],
            ]);
        }

        if ($validation->status !== ValidationStatus::Pending) {
            throw ValidationException::withMessages([
                'validation' => ['Cette étape a déjà été traitée.'],
            ]);
        }

        $step = $validation->workflowStep()->firstOrFail();

        if ($actor->hasPermission('workflows.manage')) {
            return;
        }

        if ($step->responsible_user_id && $step->responsible_user_id === $actor->id) {
            return;
        }

        if ($step->responsible_role_id && $actor->roles()->where('roles.id', $step->responsible_role_id)->exists()) {
            return;
        }

        if (! $step->responsible_user_id && ! $step->responsible_role_id && $actor->hasPermission('validations.act')) {
            return;
        }

        throw ValidationException::withMessages([
            'validation' => ['Vous n\'êtes pas responsable de cette étape de validation.'],
        ]);
    }

    private function assertIsCurrentStep(Validation $validation): void
    {
        $current = $this->currentPending($validation->document()->firstOrFail());

        if (! $current || $current->id !== $validation->id) {
            throw ValidationException::withMessages([
                'validation' => ['Ce n\'est pas l\'étape courante du workflow.'],
            ]);
        }
    }

    private function allMandatoryApproved(Document $document): bool
    {
        $validations = $this->listForDocument($document);

        $mandatory = $validations->filter(
            fn (Validation $v) => $v->workflowStep?->is_mandatory ?? true
        );

        if ($mandatory->isEmpty()) {
            return $validations->every(fn (Validation $v) => $v->status === ValidationStatus::Approved);
        }

        return $mandatory->every(fn (Validation $v) => $v->status === ValidationStatus::Approved);
    }

    private function loadDocument(Document $document): Document
    {
        return $document->load([
            'folder',
            'author',
            'owner',
            'currentVersion',
            'workflow.steps',
            'validations.workflowStep',
            'validations.user',
        ]);
    }

    private function notifyStakeholders(
        Document $document,
        Validation $validation,
        string $action,
        ?int $excludeUserId,
    ): void {
        $ids = collect([$document->author_id, $document->owner_id])
            ->filter()
            ->unique()
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($id) => $id === $excludeUserId));

        $step = $validation->workflowStep;
        if ($step?->responsible_user_id) {
            $ids->push($step->responsible_user_id);
        }

        $ids = $ids->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new ValidationActionNotification($document, $validation->fresh(), $action)
        );
    }
}
