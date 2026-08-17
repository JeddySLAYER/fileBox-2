<?php

use App\Models\Document;
use App\Models\Folder;
use App\Services\Trash\TrashService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('suppression definitive d un document en corbeille', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'T', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'À détruire',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('x.pdf', 8, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$id}")->assertOk();
    $this->deleteJson("/api/documents/{$id}/permanent")->assertOk();

    expect(Document::withTrashed()->find($id))->toBeNull();
    expect(Storage::disk('local')->directories("documents/{$id}"))->toBe([]);
});

test('vider la corbeille supprime documents et dossiers visibles', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Poubelle', 'created_by' => adminUser()->id]);

    $docId = $this->post('/api/documents', [
        'title' => 'Fichier',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('f.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$docId}")->assertOk();
    $this->deleteJson("/api/folders/{$folder->id}")->assertOk();

    $this->postJson('/api/trash/empty')
        ->assertOk()
        ->assertJsonPath('deleted.documents', 1)
        ->assertJsonPath('deleted.folders', 1);

    expect(Document::withTrashed()->find($docId))->toBeNull()
        ->and(Folder::withTrashed()->find($folder->id))->toBeNull();
});

test('la purge retire les elements en corbeille depuis plus de 30 jours', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Old', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Ancien',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$id}")->assertOk();

    Document::onlyTrashed()->whereKey($id)->update([
        'deleted_at' => now()->subDays(TrashService::RETENTION_DAYS + 1),
    ]);

    $this->artisan('trash:purge')->assertSuccessful();

    expect(Document::withTrashed()->find($id))->toBeNull();
});

test('un document recent en corbeille n est pas purge', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Recent', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Récent',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$id}")->assertOk();
    $this->artisan('trash:purge')->assertSuccessful();

    expect(Document::onlyTrashed()->find($id))->not->toBeNull();
});

test('un collaborateur ne vide pas la corbeille d un autre espace', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'AdminOnly', 'created_by' => $admin->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Secret',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('s.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->deleteJson("/api/documents/{$id}")->assertOk();

    Sanctum::actingAs(collaboratorUser());
    $this->postJson('/api/trash/empty')->assertOk();

    expect(Document::onlyTrashed()->find($id))->not->toBeNull();
});
