<?php

namespace App\Services\Access;

use App\Enums\AccessAbility;
use App\Events\Access\AccessGranted;
use App\Events\Access\AccessRevoked;
use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Notifications\AccessExpiringNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessService
{
    /** @var array<string, list<string>> */
    private const IMPLIES = [
        'manage' => ['view', 'download', 'edit', 'delete', 'share', 'manage'],
        'edit' => ['view', 'download', 'edit'],
        'share' => ['view', 'share'],
        'delete' => ['view', 'delete'],
        'download' => ['view', 'download'],
        'view' => ['view'],
    ];

    /**
     * @param  array{user_id: int, abilities: array<int, string>, starts_at?: string|null, ends_at?: string|null}  $data
     */
    public function grant(User $grantor, Model $accessible, array $data): Access
    {
        if (! $accessible instanceof Document && ! $accessible instanceof Folder) {
            throw ValidationException::withMessages([
                'accessible' => ['La ressource doit être un document ou un dossier.'],
            ]);
        }

        if ($data['user_id'] === $grantor->id) {
            throw ValidationException::withMessages([
                'user_id' => ['Vous ne pouvez pas vous accorder un accès à vous-même.'],
            ]);
        }

        $abilities = array_values(array_unique($data['abilities']));
        $this->assertValidAbilities($abilities);
        $this->assertGrantorCanGrant($grantor, $accessible);

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && $data['ends_at'] < $data['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['La date de fin doit être postérieure à la date de début.'],
            ]);
        }

        $access = DB::transaction(function () use ($grantor, $accessible, $data, $abilities) {
            return Access::query()->updateOrCreate(
                [
                    'user_id' => $data['user_id'],
                    'accessible_type' => $accessible->getMorphClass(),
                    'accessible_id' => $accessible->getKey(),
                ],
                [
                    'abilities' => $abilities,
                    'starts_at' => $data['starts_at'] ?? null,
                    'ends_at' => $data['ends_at'] ?? null,
                    'granted_by' => $grantor->id,
                    'expiry_notified_at' => null,
                ]
            );
        });

        $access->load(['user', 'grantor', 'accessible']);
        event(new AccessGranted($access, $grantor, $accessible));

        return $access;
    }

    /**
     * Accorde le même accès à plusieurs utilisateurs.
     *
     * @param  array{user_ids?: array<int>, user_id?: int, abilities: array<int, string>, starts_at?: string|null, ends_at?: string|null}  $data
     * @return array<int, Access>
     */
    public function grantMany(User $grantor, Model $accessible, array $data): array
    {
        $userIds = array_values(array_unique(array_map(
            'intval',
            $data['user_ids'] ?? (isset($data['user_id']) ? [$data['user_id']] : []),
        )));

        if ($userIds === []) {
            throw ValidationException::withMessages([
                'user_ids' => ['Sélectionnez au moins un utilisateur.'],
            ]);
        }

        $accesses = [];
        foreach ($userIds as $userId) {
            $accesses[] = $this->grant($grantor, $accessible, [
                ...$data,
                'user_id' => $userId,
            ]);
        }

        return $accesses;
    }

    public function revokeExpired(): int
    {
        $now = now();
        $count = 0;

        Access::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->orderBy('id')
            ->chunkById(200, function (Collection $batch) use (&$count): void {
                foreach ($batch as $access) {
                    $this->revoke($access);
                    $count++;
                }
            });

        return $count;
    }

    public function revoke(Access $access): void
    {
        $access->loadMissing(['user', 'accessible']);

        event(new AccessRevoked($access));

        $access->delete();
    }

    /**
     * Notifie les accès qui expirent dans les 24h (une seule fois).
     */
    public function notifyUpcomingExpirations(): int
    {
        $count = 0;
        $now = now();
        $horizon = $now->copy()->addDay();

        Access::query()
            ->whereNotNull('ends_at')
            ->whereNull('expiry_notified_at')
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $horizon)
            ->with(['user', 'accessible'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $batch) use (&$count): void {
                foreach ($batch as $access) {
                    if (! $access->user) {
                        continue;
                    }

                    $access->user->notify(new AccessExpiringNotification($access));
                    $access->expiry_notified_at = now();
                    $access->save();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  array{abilities?: array<int, string>, starts_at?: string|null, ends_at?: string|null}  $data
     */
    public function update(Access $access, array $data): Access
    {
        if (isset($data['abilities'])) {
            $abilities = array_values(array_unique($data['abilities']));
            $this->assertValidAbilities($abilities);
            $access->abilities = $abilities;
        }

        if (array_key_exists('starts_at', $data)) {
            $access->starts_at = $data['starts_at'];
        }

        if (array_key_exists('ends_at', $data)) {
            $access->ends_at = $data['ends_at'];
            $access->expiry_notified_at = null;
        }

        if ($access->starts_at && $access->ends_at && $access->ends_at->lt($access->starts_at)) {
            throw ValidationException::withMessages([
                'ends_at' => ['La date de fin doit être postérieure à la date de début.'],
            ]);
        }

        $access->save();

        return $access->load(['user', 'grantor', 'accessible']);
    }

    public function listForResource(Model $accessible): Collection
    {
        return Access::query()
            ->where('accessible_type', $accessible->getMorphClass())
            ->where('accessible_id', $accessible->getKey())
            ->with(['user', 'grantor'])
            ->latest()
            ->get();
    }

    public function listForUser(User $user, bool $activeOnly = true): Collection
    {
        $query = Access::query()
            ->where('user_id', $user->id)
            ->with(['accessible', 'grantor'])
            ->latest();

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    public function userCan(User $user, Model $accessible, string $ability): bool
    {
        if ($accessible instanceof Document) {
            return $this->hasDocumentAbility($user, $accessible, $ability);
        }

        if ($accessible instanceof Folder) {
            return $this->hasFolderAbility($user, $accessible, $ability);
        }

        return false;
    }

    public function hasAnyActiveAccess(User $user): bool
    {
        return Access::query()->where('user_id', $user->id)->active()->exists();
    }

    /**
     * Documents visibles via accès direct ou héritage dossier.
     *
     * @return list<int>
     */
    public function accessibleDocumentIds(User $user): array
    {
        $accesses = $this->listForUser($user, activeOnly: true);
        $ids = [];

        foreach ($accesses as $access) {
            if ($access->accessible_type === 'document') {
                $ids[] = (int) $access->accessible_id;
            }

            if ($access->accessible_type === 'folder') {
                $folder = Folder::query()->find($access->accessible_id);
                if ($folder) {
                    $ids = array_merge($ids, $this->documentIdsInFolderTree($folder));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Dossiers visibles via accès direct (inclut descendants).
     *
     * @return list<int>
     */
    public function accessibleFolderIds(User $user): array
    {
        $accesses = $this->listForUser($user, activeOnly: true);
        $ids = [];

        foreach ($accesses as $access) {
            if ($access->accessible_type === 'folder') {
                $ids[] = (int) $access->accessible_id;
                $folder = Folder::query()->find($access->accessible_id);
                if ($folder) {
                    $ids = array_merge($ids, $this->descendantFolderIds($folder));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function documentIdsInFolderTree(Folder $folder): array
    {
        $folderIds = array_merge([$folder->id], $this->descendantFolderIds($folder));

        return Document::query()->whereIn('folder_id', $folderIds)->pluck('id')->all();
    }

    /**
     * @return list<int>
     */
    private function descendantFolderIds(Folder $folder): array
    {
        $ids = [];
        foreach ($folder->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->descendantFolderIds($child));
        }

        return $ids;
    }

    private function hasDocumentAbility(User $user, Document $document, string $ability): bool
    {
        $direct = Access::query()
            ->where('user_id', $user->id)
            ->where('accessible_type', $document->getMorphClass())
            ->where('accessible_id', $document->id)
            ->active()
            ->first();

        if ($direct && $this->abilitiesCover($direct->abilities ?? [], $ability)) {
            return true;
        }

        // Héritage : accès dossier → documents du dossier et sous-dossiers
        $folder = $document->folder;
        while ($folder) {
            if ($this->hasFolderAbility($user, $folder, $ability)) {
                return true;
            }
            $folder = $folder->parent;
        }

        return false;
    }

    private function hasFolderAbility(User $user, Folder $folder, string $ability): bool
    {
        $current = $folder;

        // ponytail: walk ancestors O(depth); upgrade: closure table / path column
        while ($current) {
            $access = Access::query()
                ->where('user_id', $user->id)
                ->where('accessible_type', $current->getMorphClass())
                ->where('accessible_id', $current->id)
                ->active()
                ->first();

            if ($access && $this->abilitiesCover($access->abilities ?? [], $ability)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function abilitiesCover(array $abilities, string $needed): bool
    {
        foreach ($abilities as $ability) {
            $implied = self::IMPLIES[$ability] ?? [$ability];
            if (in_array($needed, $implied, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function assertValidAbilities(array $abilities): void
    {
        if ($abilities === []) {
            throw ValidationException::withMessages([
                'abilities' => ['Au moins une capacité est requise.'],
            ]);
        }

        $allowed = array_column(AccessAbility::cases(), 'value');

        foreach ($abilities as $ability) {
            if (! in_array($ability, $allowed, true)) {
                throw ValidationException::withMessages([
                    'abilities' => ["Capacité invalide : {$ability}."],
                ]);
            }
        }
    }

    private function assertGrantorCanGrant(User $grantor, Model $accessible): void
    {
        if ($grantor->hasPermission('accesses.manage')) {
            return;
        }

        if ($accessible instanceof Document) {
            if ($accessible->owner_id === $grantor->id || $accessible->author_id === $grantor->id) {
                return;
            }

            if ($this->userCan($grantor, $accessible, 'share') || $this->userCan($grantor, $accessible, 'manage')) {
                return;
            }
        }

        if ($accessible instanceof Folder) {
            if ($accessible->created_by === $grantor->id) {
                return;
            }

            if ($this->userCan($grantor, $accessible, 'share') || $this->userCan($grantor, $accessible, 'manage')) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'accessible' => ['Vous ne pouvez partager que les ressources sur lesquelles vous avez le droit de partage.'],
        ]);
    }
}
