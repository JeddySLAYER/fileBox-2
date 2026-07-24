<?php

use App\Models\Document;
use App\Models\Folder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function editableDocFolder(): Folder
{
    return Folder::query()->create([
        'name' => 'Editables',
        'created_by' => adminUser()->id,
    ]);
}

test('un document is_editable peut être modifié en ligne', function () {
    Sanctum::actingAs(adminUser());
    $folder = editableDocFolder();

    $id = $this->post('/api/documents', [
        'title' => 'Note éditable',
        'folder_id' => $folder->id,
        'is_editable' => true,
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'contenu initial'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->getJson("/api/documents/{$id}/content")
        ->assertOk()
        ->assertJsonPath('content', 'contenu initial');

    $this->putJson("/api/documents/{$id}/content", [
        'content' => 'contenu modifié depuis le site',
        'change_summary' => 'édition web',
    ])->assertOk()
        ->assertJsonPath('document.current_version.version_number', 2);

    expect(Document::query()->find($id)->versions()->count())->toBe(2);
});

test('un document non éditable refuse l édition en ligne et exige un upload', function () {
    Sanctum::actingAs(adminUser());
    $folder = editableDocFolder();

    $id = $this->post('/api/documents', [
        'title' => 'PDF scan',
        'folder_id' => $folder->id,
        'is_editable' => false,
        'file' => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->getJson("/api/documents/{$id}/content")->assertUnprocessable();

    $this->putJson("/api/documents/{$id}/content", [
        'content' => 'tentative illégale',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['is_editable']);

    $this->post("/api/documents/{$id}/versions", [
        'file' => UploadedFile::fake()->create('scan-v2.pdf', 25, 'application/pdf'),
        'change_summary' => 'réupload obligatoire',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.current_version.version_number', 2);
});
