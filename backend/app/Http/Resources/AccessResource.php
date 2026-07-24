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
        $accessible = $this->whenLoaded('accessible') ? $this->accessible : null;

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
            'accessible_type' => $this->accessible_type,
            'accessible_id' => $this->accessible_id,
            'accessible' => $this->when($accessible !== null, function () use ($accessible) {
                if ($accessible instanceof Document) {
                    return [
                        'type' => 'document',
                        'id' => $accessible->id,
                        'title' => $accessible->title,
                        'reference' => $accessible->reference,
                    ];
                }

                if ($accessible instanceof Folder) {
                    return [
                        'type' => 'folder',
                        'id' => $accessible->id,
                        'name' => $accessible->name,
                    ];
                }

                return null;
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
