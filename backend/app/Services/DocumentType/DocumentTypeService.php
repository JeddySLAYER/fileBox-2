<?php

namespace App\Services\DocumentType;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class DocumentTypeService
{
    public function list(bool $trashed = false): Collection
    {
        $query = DocumentType::query()
            ->with('defaultWorkflow')
            ->withCount('documents')
            ->orderBy('name');

        if ($trashed) {
            $query->onlyTrashed();
        }

        return $query->get();
    }

    /**
     * @param  array{name: string, slug?: string, description?: string|null, default_workflow_id?: int|null}  $data
     */
    public function create(array $data): DocumentType
    {
        return DocumentType::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'default_workflow_id' => $data['default_workflow_id'] ?? null,
        ])->load('defaultWorkflow')->loadCount('documents');
    }

    /**
     * @param  array{name?: string, slug?: string, description?: string|null, default_workflow_id?: int|null}  $data
     */
    public function update(DocumentType $type, array $data): DocumentType
    {
        $type->fill(collect($data)->only([
            'name',
            'slug',
            'description',
            'default_workflow_id',
        ])->all());
        $type->save();

        return $type->load('defaultWorkflow')->loadCount('documents');
    }

    public function delete(DocumentType $type): void
    {
        $type->delete();
    }

    public function restore(DocumentType $type): DocumentType
    {
        $type->restore();

        return $type->load('defaultWorkflow')->loadCount('documents');
    }
}
