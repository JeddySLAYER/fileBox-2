<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Validation */
class ValidationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'status' => $this->status?->value ?? $this->status,
            'comment' => $this->comment,
            'sla_hours' => $this->sla_hours,
            'due_at' => $this->due_at,
            'reminder_hours_before' => $this->reminder_hours_before,
            'remind_on_overdue' => (bool) $this->remind_on_overdue,
            'is_overdue' => $this->due_at !== null
                && $this->status?->value === 'en_attente'
                && $this->due_at->isPast(),
            'validated_at' => $this->validated_at,
            'document' => $this->whenLoaded('document', function () {
                $doc = $this->document;
                if (! $doc) {
                    return null;
                }

                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'reference' => $doc->reference,
                    'status' => $doc->status?->value ?? $doc->status,
                    'folder' => $doc->relationLoaded('folder') && $doc->folder ? [
                        'id' => $doc->folder->id,
                        'name' => $doc->folder->name,
                    ] : null,
                    'project' => $doc->relationLoaded('project') && $doc->project ? [
                        'id' => $doc->project->id,
                        'name' => $doc->project->name,
                    ] : null,
                    'author' => $doc->relationLoaded('author') && $doc->author ? [
                        'id' => $doc->author->id,
                        'name' => $doc->author->name,
                    ] : null,
                ];
            }),
            'workflow_step' => $this->whenLoaded('workflowStep', fn () => new WorkflowStepResource($this->workflowStep)),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
