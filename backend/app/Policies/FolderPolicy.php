<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessService;

class FolderPolicy
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('folders.view')
            || $this->accessService->hasAnyActiveAccess($actor);
    }

    public function view(User $actor, Folder $folder): bool
    {
        return $actor->hasPermission('folders.view')
            || $this->accessService->userCan($actor, $folder, 'view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('folders.create');
    }

    public function update(User $actor, Folder $folder): bool
    {
        return $actor->hasPermission('folders.update')
            || $this->accessService->userCan($actor, $folder, 'edit')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }

    public function delete(User $actor, Folder $folder): bool
    {
        return $actor->hasPermission('folders.delete')
            || $this->accessService->userCan($actor, $folder, 'delete')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }

    public function restore(User $actor, Folder $folder): bool
    {
        return $actor->hasPermission('folders.update');
    }

    public function share(User $actor, Folder $folder): bool
    {
        return $actor->hasPermission('accesses.manage')
            || $actor->hasPermission('folders.update')
            || $this->accessService->userCan($actor, $folder, 'share')
            || $this->accessService->userCan($actor, $folder, 'manage');
    }
}
