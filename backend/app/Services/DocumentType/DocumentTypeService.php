<?php

namespace App\Services\DocumentType;

use App\Models\Document;
use App\Models\DocumentType;
use App\Support\SoftDeleteArchive;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentTypeService
{
    public function list(): Collection
    {
        return DocumentType::query()
            ->with('defaultWorkflow')
            ->withCount('documents')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, slug?: string, description?: string|null, default_workflow_id?: int|null}  $data
     */
    public function create(array $data): DocumentType
    {
        $data = $this->normalizeWorkflowFields($data);

        return DocumentType::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'default_workflow_id' => $data['default_workflow_id'] ?? null,
            'requires_workflow' => $data['requires_workflow'] ?? false,
        ])->load('defaultWorkflow')->loadCount('documents');
    }

    /**
     * @param  array{name?: string, slug?: string, description?: string|null, default_workflow_id?: int|null}  $data
     */
    public function update(DocumentType $type, array $data): DocumentType
    {
        $data = $this->normalizeWorkflowFields($data, $type);

        $type->fill(collect($data)->only([
            'name',
            'slug',
            'description',
            'default_workflow_id',
            'requires_workflow',
        ])->all());
        $type->save();

        return $type->load('defaultWorkflow')->loadCount('documents');
    }

    public function delete(DocumentType $type): void
    {
        DB::transaction(function () use ($type) {
            Document::query()->where('document_type_id', $type->id)->update(['document_type_id' => null]);
            SoftDeleteArchive::archive($type, ['slug']);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function normalizeWorkflowFields(array $data, ?DocumentType $existing = null): array
    {
        // requires_workflow impose un default_workflow_id (validé par la FormRequest).
        return $data;
    }
}
