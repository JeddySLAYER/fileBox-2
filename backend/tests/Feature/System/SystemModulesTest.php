<?php

use App\Models\Document;
use App\Models\Folder;
use App\Models\SystemSetting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('paramètres système : liste et mise à jour', function () {
    $this->seed(SystemSettingSeeder::class);
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/settings')
        ->assertOk()
        ->assertJsonFragment(['key' => 'app.name']);

    $this->putJson('/api/settings', [
        'key' => 'app.name',
        'value' => 'FileBox Pro',
        'type' => 'string',
    ])->assertOk()
        ->assertJsonPath('setting.value', 'FileBox Pro');

    expect(SystemSetting::query()->where('key', 'app.name')->value('value'))->toBe('FileBox Pro');
});

test('recherche documentaire multicritère', function () {
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'Search', 'created_by' => adminUser()->id]);

    $this->post('/api/documents', [
        'title' => 'Contrat fournisseur ACME',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->getJson('/api/search?q=ACME')
        ->assertOk()
        ->assertJsonPath('documents.data.0.title', 'Contrat fournisseur ACME');
});

test('tableau de bord retourne les compteurs', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'dashboard' => [
                'counts' => ['users', 'documents', 'folders', 'validations_pending'],
                'documents_by_status',
                'recent_documents',
                'pending_validations',
                'blocked_validations',
                'recent_activity',
            ],
        ]);
});

test('création de sauvegarde et journalisation', function () {
    Sanctum::actingAs(adminUser());

    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive non disponible');
    }

    $this->postJson('/api/backups', ['notes' => 'Test'])
        ->assertCreated()
        ->assertJsonPath('backup.status', 'completed');

    $this->getJson('/api/backups')->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/activity-logs?action=backup.created')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'backup.created');
});

test('connexion est journalisée', function () {
    $admin = adminUser();

    $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'auth.login',
        'user_id' => $admin->id,
    ]);
});
