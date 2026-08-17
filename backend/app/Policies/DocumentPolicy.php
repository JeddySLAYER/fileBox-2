<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\Access\AccessService;
use App\Services\Access\SpaceVisibility;
use App\Support\DocumentWorkflow;

class DocumentPolicy
{
    public function __construct(
        private readonly AccessService $accessService,
        private readonly SpaceVisibility $spaceVisibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('documents.view')
            || $this->accessService->hasAnyActiveAccess($actor);
    }

    public function view(User $actor, Document $document): bool
    {
        // Proposition / circuit : réservé aux décideurs (admin, chef) et au validateur de l’étape courante.
        if (in_array($document->status, [DocumentStatus::Proposed, DocumentStatus::InValidation], true)) {
            return $this->canSeePendingWorkflowDocument($actor, $document);
        }

        return $this->spaceVisibility->canViewDocument($actor, $document);
    }

    /** Admin / chef / auteur, ou validateur de l’étape courante uniquement. */
    private function canSeePendingWorkflowDocument(User $actor, Document $document): bool
    {
        if ($actor->hasRole('administrateur') || $actor->hasPermission('workflows.manage')) {
            return true;
        }

        if ((int) $actor->id === (int) $document->author_id
            || (int) $actor->id === (int) $document->owner_id) {
            return true;
        }

        $document->loadMissing('project');

        if ($document->project_id
            && $actor->managedProjects()->where('id', $document->project_id)->exists()) {
            return true;
        }

        if ($document->status !== DocumentStatus::InValidation) {
            return false;
        }

        return app(\App\Services\Validation\ValidationService::class)
            ->userIsCurrentStepAssignee($actor, $document);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('documents.create');
    }

    public function update(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.update')
            || $this->accessService->userCan($actor, $document, 'edit');
    }

    public function delete(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.delete')
            || $this->accessService->userCan($actor, $document, 'delete');
    }

    public function restore(User $actor, Document $document): bool
    {
        return $this->spaceVisibility->canViewDocument($actor, $document)
            && $actor->hasPermission('documents.update');
    }

    public function forceDelete(User $actor, Document $document): bool
    {
        return $this->delete($actor, $document);
    }

    public function archive(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.archive')
            || $this->accessService->userCan($actor, $document, 'manage');
    }

    public function download(User $actor, Document $document): bool
    {
        if (! $this->view($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.download')
            || $actor->hasPermission('documents.view')
            || $this->accessService->userCan($actor, $document, 'download')
            || $this->accessService->userCan($actor, $document, 'view');
    }

    public function version(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.update')
            || $actor->hasPermission('versions.manage')
            || $this->accessService->userCan($actor, $document, 'edit');
    }

    public function share(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        return $actor->hasPermission('documents.share')
            || $actor->hasPermission('accesses.manage')
            || (int) $actor->id === (int) $document->owner_id
            || (int) $actor->id === (int) $document->author_id
            || $this->accessService->userCan($actor, $document, 'share');
    }

    public function propose(User $actor, Document $document): bool
    {
        if (! DocumentWorkflow::canPropose($document)) {
            return false;
        }

        return $actor->id === $document->author_id
            || $actor->id === $document->owner_id
            || $actor->hasPermission('documents.update');
    }

    public function startWorkflow(User $actor, Document $document): bool
    {
        if (DocumentWorkflow::isPersonal($document)) {
            return false;
        }

        return $this->canManageProjectWorkflow($actor, $document);
    }

    public function acceptProposition(User $actor, Document $document): bool
    {
        if (! DocumentWorkflow::canAcceptProposition($document)) {
            return false;
        }

        return $this->canManageProjectWorkflow($actor, $document);
    }

    public function setCurrentVersion(User $actor, Document $document): bool
    {
        if (! $this->spaceVisibility->canViewDocument($actor, $document)) {
            return false;
        }

        if ($document->status === DocumentStatus::Archived) {
            return false;
        }

        if ($actor->hasRole('administrateur') || $actor->hasPermission('workflows.manage')) {
            return true;
        }

        $document->loadMissing('project');

        if ($document->project_id
            && $actor->managedProjects()->where('id', $document->project_id)->exists()) {
            return true;
        }

        $departmentId = $document->department_id ?? $document->project?->department_id;
        if ($departmentId
            && $actor->managedDepartments()->where('id', $departmentId)->exists()) {
            return true;
        }

        return false;
    }

    public function resetWorkflow(User $actor, Document $document): bool
    {
        if (DocumentWorkflow::isPersonal($document)) {
            return false;
        }

        return $this->canManageProjectWorkflow($actor, $document);
    }

    private function canManageProjectWorkflow(User $actor, Document $document): bool
    {
        if ($actor->hasPermission('workflows.manage')) {
            return true;
        }

        if ($document->project_id) {
            return $actor->managedProjects()->where('id', $document->project_id)->exists();
        }

        return false;
    }
}
