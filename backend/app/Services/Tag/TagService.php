<?php

namespace App\Services\Tag;

use App\Models\Document;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class TagService
{
    public function list(): Collection
    {
        return Tag::query()->withCount('documents')->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, slug?: string}  $data
     */
    public function create(array $data): Tag
    {
        return Tag::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
        ])->loadCount('documents');
    }

    /**
     * @param  array{name?: string, slug?: string}  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->fill(collect($data)->only(['name', 'slug'])->all());
        $tag->save();

        return $tag->loadCount('documents');
    }

    public function delete(Tag $tag): void
    {
        $tag->documents()->detach();
        $tag->delete();
    }

    /**
     * @param  array<int>  $tagIds
     */
    public function syncDocumentTags(Document $document, array $tagIds): Document
    {
        $document->tags()->sync($tagIds);

        return $document->load('tags');
    }
}
