<?php

namespace App\Services\Favorite;

use App\Models\Document;
use App\Models\Favorite;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FavoriteService
{
    /** @return Collection<int, Favorite> */
    public function listForUser(User $user): Collection
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->with(['favoritable' => function ($morphTo) {
                $morphTo->morphWith([
                    Document::class => ['folder:id,name'],
                    Folder::class => [],
                ]);
            }])
            ->latest()
            ->limit(50)
            ->get();
    }

    public function add(User $user, Model $favoritable): Favorite
    {
        $this->assertSupported($favoritable);

        return Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => $favoritable->getMorphClass(),
            'favoritable_id' => $favoritable->getKey(),
        ]);
    }

    public function remove(User $user, Model $favoritable): void
    {
        $this->assertSupported($favoritable);

        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $favoritable->getMorphClass())
            ->where('favoritable_id', $favoritable->getKey())
            ->delete();
    }

    public function isFavorited(User $user, Model $favoritable): bool
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $favoritable->getMorphClass())
            ->where('favoritable_id', $favoritable->getKey())
            ->exists();
    }

    private function assertSupported(Model $favoritable): void
    {
        if (! $favoritable instanceof Document && ! $favoritable instanceof Folder) {
            throw ValidationException::withMessages([
                'favorite' => ['Type de favori non supporté.'],
            ]);
        }
    }
}
