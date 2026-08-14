<?php

namespace App\Support;

use App\Models\Document;
use App\Models\User;
use App\Models\Validation;
use Illuminate\Support\Collection;

final class ValidationActors
{
    /** @return Collection<int, int> */
    public static function stepResponsibleIds(?Validation $validation): Collection
    {
        $step = $validation?->workflowStep;
        $ids = collect();

        if ($step?->responsible_user_id) {
            $ids->push((int) $step->responsible_user_id);
        }

        if ($step?->responsible_role_id) {
            $ids = $ids->merge(
                User::query()
                    ->whereHas('roles', fn ($q) => $q->where('roles.id', $step->responsible_role_id))
                    ->pluck('id')
            );
        }

        if ($ids->isEmpty()) {
            $ids = User::query()
                ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'validations.act'))
                ->pluck('id');
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->filter()->values();
    }

    /** @return Collection<int, int> */
    public static function authorOwnerIds(Document $document, ?int $excludeUserId = null): Collection
    {
        return collect([$document->author_id, $document->owner_id])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($id) => $id === $excludeUserId))
            ->values();
    }

    /** @return Collection<int, int> */
    public static function projectManagerIds(Document $document): Collection
    {
        $document->loadMissing('project');
        $managerId = $document->project?->manager_id;

        return collect($managerId ? [(int) $managerId] : []);
    }

    /** @return Collection<int, int> */
    public static function workflowManagerIds(): Collection
    {
        return User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workflows.manage'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /** @return Collection<int, int> */
    public static function administratorIds(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'administrateur'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }
}
