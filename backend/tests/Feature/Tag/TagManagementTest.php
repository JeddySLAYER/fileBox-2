<?php

use App\Models\Document;
use App\Models\Folder;
use App\Models\Tag;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('un admin peut créer un tag et l associer à un document', function () {
    Sanctum::actingAs(adminUser());

    $tag = $this->postJson('/api/tags', ['name' => 'Urgent'])
        ->assertCreated()
        ->assertJsonPath('tag.slug', 'urgent')
        ->json('tag');

    $folder = Folder::query()->create(['name' => 'F', 'created_by' => adminUser()->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc tagué',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.txt', 5, 'text/plain'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->putJson("/api/documents/{$docId}/tags", [
        'tag_ids' => [$tag['id']],
    ])->assertOk()
        ->assertJsonCount(1, 'document.tags');

    expect(Document::query()->find($docId)->tags->pluck('slug')->all())->toContain('urgent');
});

test('suppression d un tag le détache des documents', function () {
    Sanctum::actingAs(adminUser());

    $tag = Tag::query()->create(['name' => 'Temp', 'slug' => 'temp']);

    $this->deleteJson("/api/tags/{$tag->id}")->assertOk();
    expect(Tag::query()->find($tag->id))->toBeNull();
});
