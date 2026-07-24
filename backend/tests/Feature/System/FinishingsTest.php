<?php

use App\Models\Backup;
use App\Models\Folder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function guestOnlyUser(): User
{
    return User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);
}

test('invité sans RBAC ne voit que les documents partagés dans la liste', function () {
    $admin = adminUser();
    $guest = guestOnlyUser();
    Sanctum::actingAs($admin);

    $folder = Folder::query()->create(['name' => 'Privé', 'created_by' => $admin->id]);

    $sharedId = $this->post('/api/documents', [
        'title' => 'Partagé',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->post('/api/documents', [
        'title' => 'Secret',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->postJson("/api/documents/{$sharedId}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view'],
    ])->assertCreated();

    Sanctum::actingAs($guest);

    $this->getJson('/api/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Partagé');

    $this->getJson('/api/search?q=Secret')
        ->assertOk()
        ->assertJsonCount(0, 'documents.data');
});

test('prévisualisation PDF et URL signée temporaire', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'Prev', 'created_by' => $admin->id]);

    $docId = $this->post('/api/documents', [
        'title' => 'PDF preview',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->get("/api/documents/{$docId}/preview")
        ->assertOk()
        ->assertHeader('content-disposition');

    $url = $this->getJson("/api/documents/{$docId}/preview-url?expires_minutes=10")
        ->assertOk()
        ->json('url');

    expect($url)->toContain('/api/signed/documents/');

    $this->get($url)->assertOk();
});

test('sauvegarde inclut les fichiers binaires', function () {
    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive non disponible');
    }

    $this->seed(SystemSettingSeeder::class);
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'Bak', 'created_by' => $admin->id]);

    $this->post('/api/documents', [
        'title' => 'Avec fichier',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('keep.pdf', 15, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $backupId = $this->postJson('/api/backups', ['notes' => 'avec binaires'])
        ->assertCreated()
        ->json('backup.id');

    $backup = Backup::query()->findOrFail($backupId);
    $zipPath = Storage::disk('local')->path($backup->path);

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();

    $hasFile = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_starts_with((string) $name, 'files/documents/')) {
            $hasFile = true;
            break;
        }
    }
    $zip->close();

    expect($hasFile)->toBeTrue();
});

test('création document est journalisée', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Log', 'created_by' => adminUser()->id]);

    $this->post('/api/documents', [
        'title' => 'Loggé',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('l.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'document.created',
    ]);
});

test('logs système accessibles aux admins settings', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/activity-logs/system?lines=20')
        ->assertOk()
        ->assertJsonStructure(['lines']);
});
