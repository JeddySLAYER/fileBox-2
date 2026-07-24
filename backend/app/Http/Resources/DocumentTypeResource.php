<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentType */
class DocumentTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'default_workflow' => $this->whenLoaded('defaultWorkflow', fn () => $this->defaultWorkflow ? [
                'id' => $this->defaultWorkflow->id,
                'code' => $this->defaultWorkflow->code,
                'name' => $this->defaultWorkflow->name,
            ] : null),
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
