<?php

namespace App\Services\Validation;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Events\Validation\ValidationActionTaken;
use App\Models\Document;
use App\Models\User;
use App\Models\Validation;
use App\Models\Workflow;
use App\Support\DocumentWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidationService
{
    /**
     * Associe un workflow au document et crée les validations (une par étape).
     *
     * @param  array<int, array{workflow_step_id: int, amount: int, unit: string}>  $deadlines
     */
    public function start(
        Document $document,
        Workflow $workflow,
        ?int $workflowOverrideId = null,
        array $deadlines = [],
    ): Document {
        if (DocumentWorkflow::isPersonal($document)) {
            throw ValidationException::withMessages([
                'document' => ['Les documents personnels ne peuvent pas entrer en workflow de validation.'],
            ]);
        }

        if (! DocumentWorkflow::canStartValidation($document)) {
            throw ValidationException::withMessages([
                'document' => ['Ce document ne peut pas démarrer un workflow dans son état actuel.'],
            ]);
        }

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

        $slaByStep = $this->normalizeDeadlines($deadlines, $workflow);

        return DB::transaction(function () use ($document, $workflow, $workflowOverrideId, $slaByStep) {
            $document->validations()->delete();

            $document->workflow_id = $workflowOverrideId ?? $workflow->id;
            $document->status = DocumentStatus::InValidation;
            $document->save();

            $firstValidation = null;
            $ordered = $workflow->steps->sortBy('step_order')->values();

            foreach ($ordered as $index => $step) {
                $slaHours = $slaByStep[$step->id] ?? null;
                $validation = Validation::query()->create([
                    'document_id' => $document->id,
                    'workflow_step_id' => $step->id,
                    'status' => ValidationStatus::Pending,
                    'sla_hours' => $slaHours,
                    // Délai actif uniquement sur la première étape ; les suivantes au passage
                    'due_at' => $index === 0 && $slaHours !== null
                        ? now()->addHours($slaHours)
                        : null,
                ]);

                $firstValidation ??= $validation;
            }

            $loaded = $this->loadDocument($document);

            if ($firstValidation) {
                event(new ValidationActionTaken(
                    activityAction: 'validation.started',
                    document: $loaded,
                    validation: $firstValidation,
                    notificationAction: 'started',
                    description: "Workflow démarré sur {$loaded->reference}",
                    properties: ['workflow_id' => $workflow->id],
                ));
            }

            return $loaded;
        });
    }

    /**
     * @param  array<int, array{workflow_step_id: int, amount: int, unit: string}>  $deadlines
     * @return array<int, int> step_id => sla_hours
     */
    private function normalizeDeadlines(array $deadlines, Workflow $workflow): array
    {
        if ($deadlines === []) {
            return [];
        }

        $stepIds = $workflow->steps->pluck('id')->all();
        $map = [];

        foreach ($deadlines as $row) {
            $stepId = (int) $row['workflow_step_id'];
            if (! in_array($stepId, $stepIds, true)) {
                throw ValidationException::withMessages([
                    'deadlines' => ["L'étape #{$stepId} n'appartient pas au workflow choisi."],
                ]);
            }

            $amount = (int) $row['amount'];
            $unit = $row['unit'];
            $map[$stepId] = $unit === 'days' ? $amount * 24 : $amount;
        }

        return $map;
    }

    private function activateDueAt(Validation $validation): void
    {
        if ($validation->sla_hours === null || $validation->due_at !== null) {
            return;
        }

        $validation->due_at = now()->addHours($validation->sla_hours);
        $validation->save();
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
            } else {
                $next = $this->currentPending($document);
                if ($next) {
                    $this->activateDueAt($next);
                }
            }

            $loaded = $this->loadDocument($document);
            event(new ValidationActionTaken(
                activityAction: 'validation.approved',
                document: $loaded,
                validation: $validation,
                notificationAction: 'approved',
                actor: $actor,
                excludeUserId: $actor->id,
                description: "Étape approuvée sur {$loaded->reference}",
            ));

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
            event(new ValidationActionTaken(
                activityAction: 'validation.rejected',
                document: $loaded,
                validation: $validation,
                notificationAction: 'rejected',
                actor: $actor,
                excludeUserId: $actor->id,
                description: "Document rejeté : {$loaded->reference}",
            ));

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
            event(new ValidationActionTaken(
                activityAction: 'validation.correction_requested',
                document: $loaded,
                validation: $validation,
                notificationAction: 'correction_requested',
                actor: $actor,
                excludeUserId: $actor->id,
                description: "Correction demandée sur {$loaded->reference}",
            ));

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

        if ($document->status === DocumentStatus::InValidation) {
            throw ValidationException::withMessages([
                'document' => ['Le document est déjà en validation.'],
            ]);
        }

        $document->validations()->delete();
        $document->status = DocumentStatus::Draft;
        $document->save();

        return $this->loadDocument($document);
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

        // Superviseurs (admin, direction, responsables métier)
        if ($actor->hasPermission('workflows.manage') || $actor->hasPermission('validations.act')) {
            return;
        }

        // Qui de droit : responsable nommé sur l'étape (même sans permission globale)
        if ($step->responsible_user_id && $step->responsible_user_id === $actor->id) {
            return;
        }

        if ($step->responsible_role_id && $actor->roles()->where('roles.id', $step->responsible_role_id)->exists()) {
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
}
