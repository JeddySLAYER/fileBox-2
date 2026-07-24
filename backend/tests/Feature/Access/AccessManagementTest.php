<?php

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Notifications\AccessGrantedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function externalUser(): User
{
    // Aucun rôle → uniquement les accès spécifiques
    return User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);
}

test('accorder un accès document notifie le destinataire', function () {
    Notification::fake();

    $admin = adminUser();
    Sanctum::actingAs($admin);
    $guest = externalUser();

    $folder = Folder::query()->create(['name' => 'A', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Secret',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view', 'download'],
        'ends_at' => now()->addDays(7)->toISOString(),
    ])->assertCreated()
        ->assertJsonPath('access.is_temporary', true);

    Notification::assertSentTo($guest, AccessGrantedNotification::class);
});

test('accès sur dossier donne accès aux documents enfants sans permission RBAC', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $guest = externalUser();

    $folder = Folder::query()->create(['name' => 'Partagé', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Dans dossier',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('d.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    // Sans accès → interdit
    Sanctum::actingAs($guest);
    $this->getJson("/api/documents/{$docId}")->assertForbidden();

    Sanctum::actingAs($admin);
    $this->postJson("/api/folders/{$folder->id}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view'],
    ])->assertCreated();

    Sanctum::actingAs($guest);
    $this->getJson("/api/documents/{$docId}")->assertOk();
    $this->getJson("/api/folders/{$folder->id}")->assertOk();
});

test('accès expiré ne figure plus dans /accesses/mine actifs', function () {
    $admin = adminUser();
    $guest = externalUser();

    $folder = Folder::query()->create(['name' => 'Exp', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-ACCESS-000001',
        'title' => 'Expiré',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'is_editable' => false,
    ]);

    Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['view'],
        'ends_at' => now()->subDay(),
        'granted_by' => $admin->id,
    ]);

    Sanctum::actingAs($guest);

    $mine = $this->getJson('/api/accesses/mine')->assertOk();
    expect(collect($mine->json('data'))->pluck('accessible_id')->all())->not->toContain($document->id);

    $this->getJson("/api/documents/{$document->id}")->assertForbidden();
});

test('un utilisateur voit ses accès dans /accesses/mine', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $guest = externalUser();

    $folder = Folder::query()->create(['name' => 'Mine', 'created_by' => $admin->id]);

    $this->postJson("/api/folders/{$folder->id}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view', 'download'],
    ])->assertCreated();

    Sanctum::actingAs($guest);

    $this->getJson('/api/accesses/mine')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
