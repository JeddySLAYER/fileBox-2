<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessService;
use App\Services\Access\SpaceVisibility;

class FolderPolicy
{
    public function __construct(
        private readonly AccessService $accessService,
        private readonly SpaceVisibility $spaceVisibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('folders.view')
            || $this->accessService->hasAnyActiveAccess($actor);
    }

    public function view(User $actor, Folder $folder): bool
    {
        return $this->spaceVisibility->canViewFolder($actor, $folder);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('folders.create');
    }

    public function update(User $actor, Folder $folder): bool
    {
        if (! $this->spaceVisibility->canViewFolder($actor, $folder)) {
            return false;
        }

        return $actor->hasPermission('folders.update')
            || $this->accessService->userCan($actor, $folder, 'edit')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }

    public function delete(User $actor, Folder $folder): bool
    {
        if (! $this->spaceVisibility->canViewFolder($actor, $folder)) {
            return false;
        }

        return $actor->hasPermission('folders.delete')
            || $this->accessService->userCan($actor, $folder, 'delete')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }

    public function restore(User $actor, Folder $folder): bool
    {
        return $this->spaceVisibility->canViewFolder($actor, $folder)
            && $actor->hasPermission('folders.update');
    }

    public function share(User $actor, Folder $folder): bool
    {
        if (! $this->spaceVisibility->canViewFolder($actor, $folder)) {
            return false;
        }

        return $actor->hasPermission('accesses.manage')
            || $actor->hasPermission('folders.update')
            || (int) $actor->id === (int) $folder->created_by
            || $this->accessService->userCan($actor, $folder, 'share')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }
}
