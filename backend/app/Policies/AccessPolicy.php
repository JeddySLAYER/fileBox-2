<?php

namespace App\Policies;

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessService;

class AccessPolicy
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('accesses.manage')
            || $actor->hasPermission('documents.share');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('accesses.manage')
            || $actor->hasPermission('documents.share');
    }

    public function createFor(User $actor, Document|Folder $resource): bool
    {
        if ($actor->hasPermission('accesses.manage')) {
            return true;
        }

        if ($resource instanceof Document) {
            return $actor->hasPermission('documents.share')
                || $this->accessService->userCan($actor, $resource, 'share');
        }

        return $actor->hasPermission('folders.update')
            || $this->accessService->userCan($actor, $resource, 'share')
            || $this->accessService->userCan($actor, $resource, 'manage');
    }

    public function update(User $actor, Access $access): bool
    {
        return $actor->hasPermission('accesses.manage')
            || $access->granted_by === $actor->id;
    }

    public function delete(User $actor, Access $access): bool
    {
        return $actor->hasPermission('accesses.manage')
            || $access->granted_by === $actor->id
            || $access->user_id === $actor->id;
    }
}
