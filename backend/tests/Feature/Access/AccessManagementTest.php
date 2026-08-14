<?php

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Permission;
use App\Models\Role;
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

test('partager un document à plusieurs utilisateurs d’un coup', function () {
    Notification::fake();

    $admin = adminUser();
    Sanctum::actingAs($admin);
    $guestA = externalUser();
    $guestB = externalUser();

    $folder = Folder::query()->create(['name' => 'Multi', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Partagé multi',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('m.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_ids' => [$guestA->id, $guestB->id],
        'abilities' => ['view', 'download'],
    ])->assertCreated()
        ->assertJsonPath('message', 'Accès accordé à 2 utilisateurs.')
        ->assertJsonCount(2, 'accesses');

    Notification::assertSentTo($guestA, AccessGrantedNotification::class);
    Notification::assertSentTo($guestB, AccessGrantedNotification::class);

    Sanctum::actingAs($guestA);
    $this->getJson("/api/documents/{$docId}")->assertOk();

    Sanctum::actingAs($guestB);
    $this->getJson("/api/documents/{$docId}")->assertOk();
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

test('un utilisateur sans droit sur la ressource ne peut pas accorder un accès', function () {
    $admin = adminUser();
    $guest = externalUser();
    $grantor = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);

    $shareRole = Role::query()->create([
        'name' => 'Share only',
        'slug' => 'share-only',
        'description' => 'Can call share endpoints',
        'is_system' => false,
    ]);
    $shareRole->permissions()->sync([
        Permission::query()->where('slug', 'documents.share')->firstOrFail()->id,
    ]);
    $grantor->roles()->attach($shareRole);

    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'Owned by admin', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Private doc',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    Sanctum::actingAs($grantor);
    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view'],
    ])->assertForbidden();
});

test('révocation automatique supprime les accès expirés', function () {
    $admin = adminUser();
    $guest = externalUser();
    $folder = Folder::query()->create(['name' => 'Auto revoke', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-ACCESS-000002',
        'title' => 'Expired auto',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'is_editable' => false,
    ]);

    $expired = Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['view'],
        'ends_at' => now()->subMinute(),
        'granted_by' => $admin->id,
    ]);

    $active = Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['download'],
        'ends_at' => now()->addDay(),
        'granted_by' => $admin->id,
    ]);

    $this->artisan('accesses:revoke-expired')->assertSuccessful();

    expect(Access::query()->whereKey($expired->id)->exists())->toBeFalse();
    expect(Access::query()->whereKey($active->id)->exists())->toBeTrue();
});

test('accès document autorise le contenu et les versions', function () {
    $admin = adminUser();
    $guest = externalUser();
    Sanctum::actingAs($admin);

    $folder = Folder::query()->create(['name' => 'Doc Content', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc with content',
        'folder_id' => $folder->id,
        'is_editable' => true,
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view', 'download'],
    ])->assertCreated();

    Sanctum::actingAs($guest);
    $this->getJson("/api/documents/{$docId}/versions")->assertOk();
    $this->getJson("/api/documents/{$docId}/content")->assertOk();
    $this->get("/api/documents/{$docId}/download")->assertOk();
});

test('un dossier partagé apparaît dans l explorateur du destinataire', function () {
    $admin = adminUser();
    $guest = collaboratorUser();
    Sanctum::actingAs($admin);

    $parent = Folder::query()->create(['name' => 'Parent privé', 'created_by' => $admin->id]);
    $shared = Folder::query()->create([
        'name' => 'Dossier partagé',
        'parent_id' => $parent->id,
        'created_by' => $admin->id,
    ]);
    $child = Folder::query()->create([
        'name' => 'Sous-dossier',
        'parent_id' => $shared->id,
        'created_by' => $admin->id,
    ]);

    $this->postJson("/api/folders/{$shared->id}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view', 'download'],
    ])->assertCreated();

    Sanctum::actingAs($guest);

    $rootNames = collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all();
    expect($rootNames)->toContain('Dossier partagé')
        ->and($rootNames)->not->toContain('Parent privé')
        ->and($rootNames)->not->toContain('Sous-dossier');

    $this->getJson("/api/folders/{$shared->id}")->assertOk();
    $childNames = collect($this->getJson("/api/folders?parent_id={$shared->id}")->assertOk()->json('data'))
        ->pluck('name')
        ->all();
    expect($childNames)->toContain('Sous-dossier');
});

test('un document partagé apparaît à la racine si le dossier n est pas visible', function () {
    $admin = adminUser();
    $guest = collaboratorUser();
    Sanctum::actingAs($admin);

    $folder = Folder::query()->create(['name' => 'Confidentiel', 'created_by' => $admin->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Note partagée',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('n.pdf', 8, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view', 'download'],
    ])->assertCreated();

    Sanctum::actingAs($guest);

    $this->getJson("/api/folders/{$folder->id}")->assertForbidden();
    $titles = collect($this->getJson('/api/documents?explorer_root=1')->assertOk()->json('data'))
        ->pluck('title')
        ->all();
    expect($titles)->toContain('Note partagée');
});

test('un collaborateur peut partager son propre document', function () {
    $author = collaboratorUser();
    $guest = collaboratorUser();
    Sanctum::actingAs($author);

    $folder = Folder::query()->create(['name' => 'Perso', 'created_by' => $author->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'À partager',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 8, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view'],
    ])->assertCreated();

    Sanctum::actingAs($guest);
    $this->getJson("/api/documents/{$docId}")->assertOk();
});
