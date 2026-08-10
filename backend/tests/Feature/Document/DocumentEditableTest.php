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

test('un fichier texte devient éditable en ligne automatiquement', function () {
    Sanctum::actingAs(adminUser());
    $folder = editableDocFolder();

    $created = $this->post('/api/documents', [
        'title' => 'Note éditable',
        'folder_id' => $folder->id,
        'is_editable' => false, // ignoré — dérivé de l'extension
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'contenu initial'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document');

    expect($created['is_editable'])->toBeTrue();

    $this->getJson("/api/documents/{$created['id']}/content")
        ->assertOk()
        ->assertJsonPath('content', 'contenu initial');

    $this->putJson("/api/documents/{$created['id']}/content", [
        'content' => 'contenu modifié depuis le site',
        'change_summary' => 'édition web',
    ])->assertOk()
        ->assertJsonPath('document.current_version.version_number', 2);

    expect(Document::query()->find($created['id'])->versions()->count())->toBe(2);
});

test('un pdf ou office n est pas éditable en ligne', function () {
    Sanctum::actingAs(adminUser());
    $folder = editableDocFolder();

    $id = $this->post('/api/documents', [
        'title' => 'PDF scan',
        'folder_id' => $folder->id,
        'is_editable' => true, // ignoré
        'file' => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    expect(Document::query()->find($id)->is_editable)->toBeFalse();

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
        ->assertJsonPath('document.current_version.version_number', 2)
        ->assertJsonPath('document.is_editable', false);
});

test('un réupload texte rend le document éditable', function () {
    Sanctum::actingAs(adminUser());
    $folder = editableDocFolder();

    $id = $this->post('/api/documents', [
        'title' => 'Contrat',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('contrat.docx', 30, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    expect(Document::query()->find($id)->is_editable)->toBeFalse();

    $this->post("/api/documents/{$id}/versions", [
        'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'version texte'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.is_editable', true);
});
