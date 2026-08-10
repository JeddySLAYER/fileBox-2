<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Favorite */
class FavoriteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->favoritable;

        return [
            'id' => $this->id,
            'type' => $this->favoritable_type,
            'created_at' => $this->created_at,
            'document' => $item instanceof Document ? [
                'id' => $item->id,
                'title' => $item->title,
                'reference' => $item->reference,
                'status' => $item->status?->value ?? $item->status,
                'folder' => $item->relationLoaded('folder') && $item->folder ? [
                    'id' => $item->folder->id,
                    'name' => $item->folder->name,
                ] : null,
            ] : null,
            'folder' => $item instanceof Folder ? [
                'id' => $item->id,
                'name' => $item->name,
            ] : null,
        ];
    }
}
