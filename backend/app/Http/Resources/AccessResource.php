<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Access */
class AccessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'abilities' => $this->abilities,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => $this->isActive(),
            'is_temporary' => $this->starts_at !== null || $this->ends_at !== null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'grantor' => $this->whenLoaded('grantor', fn () => $this->grantor ? [
                'id' => $this->grantor->id,
                'name' => $this->grantor->name,
            ] : null),
            'accessible_type' => $this->normalizeAccessibleType($this->accessible_type),
            'accessible_id' => $this->accessible_id,
            'accessible' => $this->whenLoaded('accessible', function () {
                return $this->serializeAccessible($this->accessible);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function normalizeAccessibleType(?string $type): string
    {
        return match ($type) {
            Document::class, 'document' => 'document',
            Folder::class, 'folder' => 'folder',
            default => (string) $type,
        };
    }

    /** @return array<string, mixed>|null */
    private function serializeAccessible(mixed $accessible): ?array
    {
        if ($accessible instanceof Document) {
            return [
                'type' => 'document',
                'id' => $accessible->id,
                'title' => $accessible->title,
                'name' => $accessible->title,
                'reference' => $accessible->reference,
                'status' => $accessible->status?->value ?? $accessible->status,
            ];
        }

        if ($accessible instanceof Folder) {
            return [
                'type' => 'folder',
                'id' => $accessible->id,
                'name' => $accessible->name,
                'title' => $accessible->name,
            ];
        }

        return null;
    }
}
