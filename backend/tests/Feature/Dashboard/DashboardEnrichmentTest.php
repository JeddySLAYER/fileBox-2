<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Validation;
use App\Models\Workflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('favoris document ajout et retrait', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'Fav', 'created_by' => $admin->id]);

    $docId = $this->post('/api/documents', [
        'title' => 'Doc favori',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('f.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    $this->postJson("/api/documents/{$docId}/favorite")->assertCreated()
        ->assertJsonPath('is_favorited', true);

    $this->getJson("/api/documents/{$docId}")
        ->assertOk()
        ->assertJsonPath('document.is_favorited', true);

    $this->getJson('/api/favorites')->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/documents/{$docId}/favorite")->assertOk()
        ->assertJsonPath('is_favorited', false);
});

test('rejet exige un commentaire', function () {
    $admin = adminUser();
    $validator = collaboratorUser();
    Sanctum::actingAs($admin);

    $project = Project::query()->create([
        'code' => 'PRJ-REJ',
        'name' => 'Rejet',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $folder = Folder::query()->create([
        'name' => 'R',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'À rejeter',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $workflow = Workflow::query()->create([
        'code' => 'WF-REJ',
        'name' => 'Rejet',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'is_mandatory' => true,
        'responsible_user_id' => $validator->id,
    ]);

    $this->postJson("/api/documents/{$docId}/propose")->assertOk();

    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    $validationId = Validation::query()->where('document_id', $docId)->value('id');

    Sanctum::actingAs($validator);
    $this->postJson("/api/validations/{$validationId}/reject", [])
        ->assertUnprocessable();

    $this->postJson("/api/validations/{$validationId}/reject", [
        'comment' => 'Non conforme au cahier des charges',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);
});

test('dashboard home expose partages favoris et a reprendre', function () {
    $owner = adminUser();
    Sanctum::actingAs($owner);
    $folder = Folder::query()->create(['name' => 'H', 'created_by' => $owner->id]);

    $docId = $this->post('/api/documents', [
        'title' => 'Mon doc',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('h.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    Document::query()->whereKey($docId)->update(['status' => DocumentStatus::Rejected->value]);

    $collab = collaboratorUser();
    Sanctum::actingAs($collab);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'home')
        ->assertJsonStructure([
            'dashboard' => [
                'pending_validations',
                'shared_documents',
                'needs_attention',
                'recent_comments',
                'favorites',
            ],
        ]);
});

test('dashboard overview liste validations et bloquees', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'global')
        ->assertJsonStructure([
            'dashboard' => [
                'counts' => ['validations_pending', 'validations_blocked', 'documents_archived'],
                'pending_validations',
                'blocked_validations',
                'favorites',
            ],
        ]);
});

test('dashboard ne compte que l etape courante d un workflow', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $project = Project::query()->create([
        'code' => 'PRJ-DSH-WF',
        'name' => 'Dashboard WF',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $folder = Folder::query()->create([
        'name' => 'D',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'Circuit 2 étapes',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $workflow = Workflow::query()->create([
        'code' => 'WF-DSH-CUR',
        'name' => 'Dashboard current',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);
    $workflow->steps()->create(['name' => 'S2', 'step_order' => 2, 'is_mandatory' => true]);

    $this->postJson("/api/documents/{$docId}/propose")->assertOk();
    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.counts.validations_pending', 1)
        ->assertJsonCount(1, 'dashboard.pending_validations');
});
