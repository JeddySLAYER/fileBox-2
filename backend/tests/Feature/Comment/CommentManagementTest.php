<?php

use App\Models\Comment;
use App\Models\Folder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('commentaires en fil sur un document', function () {
    Sanctum::actingAs(adminUser());

    $folder = Folder::query()->create(['name' => 'C', 'created_by' => adminUser()->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc commenté',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('c.txt', 5, 'text/plain'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $parent = $this->postJson("/api/documents/{$docId}/comments", [
        'content' => 'Premier commentaire',
    ])->assertCreated()
        ->json('comment');

    $this->postJson("/api/documents/{$docId}/comments", [
        'content' => 'Réponse',
        'parent_id' => $parent['id'],
    ])->assertCreated();

    $this->getJson("/api/documents/{$docId}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.replies');

    $this->putJson("/api/comments/{$parent['id']}", [
        'content' => 'Commentaire édité',
    ])->assertOk()
        ->assertJsonPath('comment.content', 'Commentaire édité');

    $this->deleteJson("/api/comments/{$parent['id']}")->assertOk();
    expect(Comment::withTrashed()->find($parent['id'])->trashed())->toBeTrue();
});
