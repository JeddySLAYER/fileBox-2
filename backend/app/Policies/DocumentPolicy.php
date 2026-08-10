<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\Access\AccessService;
use App\Support\DocumentWorkflow;

class DocumentPolicy
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('documents.view')
            || $this->accessService->hasAnyActiveAccess($actor);
    }

    public function view(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.view')
            || $this->accessService->userCan($actor, $document, 'view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('documents.create');
    }

    public function update(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.update')
            || $this->accessService->userCan($actor, $document, 'edit');
    }

    public function delete(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.delete')
            || $this->accessService->userCan($actor, $document, 'delete');
    }

    public function restore(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.update');
    }

    public function archive(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.archive')
            || $this->accessService->userCan($actor, $document, 'manage');
    }

    public function download(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.download')
            || $actor->hasPermission('documents.view')
            || $this->accessService->userCan($actor, $document, 'download')
            || $this->accessService->userCan($actor, $document, 'view');
    }

    public function version(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.update')
            || $actor->hasPermission('versions.manage')
            || $this->accessService->userCan($actor, $document, 'edit');
    }

    public function share(User $actor, Document $document): bool
    {
        return $actor->hasPermission('documents.share')
            || $actor->hasPermission('accesses.manage')
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
        if (! DocumentWorkflow::canStartValidation($document)) {
            return false;
        }

        return $this->canManageProjectWorkflow($actor, $document);
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
