<?php

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Folder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('un admin peut gérer les types de documents', function () {
    Sanctum::actingAs(adminUser());

    $created = $this->postJson('/api/document-types', [
        'name' => 'Contrat',
        'description' => 'Contrats commerciaux',
    ])->assertCreated()
        ->assertJsonPath('document_type.slug', 'contrat')
        ->json('document_type');

    $this->getJson('/api/document-types')->assertOk();

    $this->putJson("/api/document-types/{$created['id']}", [
        'name' => 'Contrat cadre',
    ])->assertOk()
        ->assertJsonPath('document_type.name', 'Contrat cadre');

    $this->deleteJson("/api/document-types/{$created['id']}")->assertOk();
    $archived = DocumentType::withTrashed()->find($created['id']);
    expect($archived->trashed())->toBeTrue()
        ->and($archived->slug)->not->toBe('contrat');

    $this->postJson('/api/document-types', [
        'name' => 'Contrat',
    ])->assertCreated()
        ->assertJsonPath('document_type.slug', 'contrat');
});

test('supprimer un type détache les documents concernés', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Types', 'created_by' => adminUser()->id]);

    $type = $this->postJson('/api/document-types', [
        'name' => 'Note interne',
    ])->assertCreated()->json('document_type');

    $id = $this->post('/api/documents', [
        'title' => 'Note',
        'folder_id' => $folder->id,
        'document_type_id' => $type['id'],
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'abc'),
    ], ['Accept' => 'application/json'])->json('document.id');

    expect(Document::query()->find($id)?->document_type_id)->toBe($type['id']);

    $this->putJson("/api/document-types/{$type['id']}", [
        'name' => 'Note interne MAJ',
        'description' => 'Mise à jour',
    ])->assertOk()
        ->assertJsonPath('document_type.name', 'Note interne MAJ');

    $this->deleteJson("/api/document-types/{$type['id']}")->assertOk();

    expect(Document::query()->find($id)?->document_type_id)->toBeNull();
});
