<?php

namespace App\Http\Resources;

use App\Support\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Document */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'summary' => $this->summary,
            'ai_analysis' => $this->ai_analysis,
            'ai_processed_at' => $this->ai_processed_at,
            'status' => $this->status?->value ?? $this->status,
            'is_personal' => DocumentWorkflow::isPersonal($this->resource),
            'subject_to_workflow' => DocumentWorkflow::subjectToWorkflow($this->resource),
            'recommends_workflow' => DocumentWorkflow::recommendsWorkflow($this->resource),
            'can_propose' => DocumentWorkflow::canPropose($this->resource),
            'can_accept_proposition' => $request->user()?->can('acceptProposition', $this->resource) ?? false,
            'can_set_current_version' => $request->user()?->can('setCurrentVersion', $this->resource) ?? false,
            'requires_workflow' => DocumentWorkflow::requiresWorkflow($this->resource),
            'can_start_workflow' => DocumentWorkflow::canStartValidation($this->resource),
            'confidentiality' => $this->confidentiality?->value ?? $this->confidentiality,
            'is_editable' => $this->is_editable,
            'language' => $this->language,
            'folder' => $this->whenLoaded('folder', fn () => $this->folder ? [
                'id' => $this->folder->id,
                'name' => $this->folder->name,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'code' => $this->project->code,
            ] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'document_type' => $this->whenLoaded('documentType', fn () => $this->documentType ? [
                'id' => $this->documentType->id,
                'name' => $this->documentType->name,
                'slug' => $this->documentType->slug,
                'requires_workflow' => (bool) $this->documentType->requires_workflow,
            ] : null),
            'workflow' => $this->whenLoaded('workflow', fn () => $this->workflow ? [
                'id' => $this->workflow->id,
                'code' => $this->workflow->code,
                'name' => $this->workflow->name,
            ] : null),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ] : null),
            'current_version' => $this->whenLoaded('currentVersion', fn () => new VersionResource($this->currentVersion)),
            'versions' => VersionResource::collection($this->whenLoaded('versions')),
            'validations' => ValidationResource::collection($this->whenLoaded('validations')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])),
            'archived_at' => $this->archived_at,
            'is_favorited' => (bool) ($this->is_favorited ?? false),
            'can_share' => $request->user()?->can('share', $this->resource) ?? false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
