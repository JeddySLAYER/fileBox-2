<?php

use App\Enums\DocumentStatus;
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

function makeFolder(): Folder
{
    return Folder::query()->create([
        'name' => 'Docs',
        'created_by' => adminUser()->id,
    ]);
}

test('création d un document produit une référence et une version initiale', function () {
    Sanctum::actingAs(adminUser());
    $folder = makeFolder();

    $response = $this->post('/api/documents', [
        'title' => 'Contrat cadre',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('document.title', 'Contrat cadre')
        ->assertJsonPath('document.status', 'brouillon')
        ->assertJsonPath('document.current_version.version_number', 1);

    expect($response->json('document.reference'))->toStartWith('DOC-'.now()->year.'-');

    $document = Document::query()->find($response->json('document.id'));
    expect($document->versions()->count())->toBe(1);
    Storage::disk('local')->assertExists($document->currentVersion->file_path);
});

test('une nouvelle version est créée sans écraser l ancienne', function () {
    Sanctum::actingAs(adminUser());
    $folder = makeFolder();

    $created = $this->post('/api/documents', [
        'title' => 'Rapport',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('v1.txt', 10, 'text/plain'),
    ], ['Accept' => 'application/json'])->json('document');

    $this->post("/api/documents/{$created['id']}/versions", [
        'file' => UploadedFile::fake()->create('v2.txt', 20, 'text/plain'),
        'change_summary' => 'Mise à jour',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.current_version.version_number', 2);

    expect(Document::query()->find($created['id'])->versions()->count())->toBe(2);
});

test('un document archivé ne peut plus être modifié', function () {
    Sanctum::actingAs(adminUser());
    $folder = makeFolder();

    $id = $this->post('/api/documents', [
        'title' => 'Archive me',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$id}/archive")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Archived->value);

    $this->putJson("/api/documents/{$id}", [
        'title' => 'Nouveau titre',
    ])->assertUnprocessable();
});

test('soft delete puis restauration d un document', function () {
    Sanctum::actingAs(adminUser());
    $folder = makeFolder();

    $id = $this->post('/api/documents', [
        'title' => 'Temp',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$id}")->assertOk();
    expect(Document::withTrashed()->find($id)->trashed())->toBeTrue();

    $this->postJson("/api/documents/{$id}/restore")
        ->assertOk()
        ->assertJsonPath('document.status', 'brouillon');
});

test('téléchargement de la version courante', function () {
    Sanctum::actingAs(adminUser());
    $folder = makeFolder();

    $id = $this->post('/api/documents', [
        'title' => 'Download me',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->get("/api/documents/{$id}/download")
        ->assertOk();
});

test('un utilisateur sans permission documents ne peut pas créer', function () {
    // invite n'a pas documents.create
    $invite = \App\Models\User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $invite->roles()->attach(
        \App\Models\Role::query()->where('slug', 'invite')->firstOrFail()
    );

    Sanctum::actingAs($invite);
    $folder = makeFolder();

    $this->post('/api/documents', [
        'title' => 'Interdit',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertForbidden();
});
