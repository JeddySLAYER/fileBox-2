<?php

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
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

test('favoris dossier ajout et retrait', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = Folder::query()->create(['name' => 'FavFolder', 'created_by' => $admin->id]);

    $this->postJson("/api/folders/{$folder->id}/favorite")->assertCreated()
        ->assertJsonPath('is_favorited', true);

    $this->getJson("/api/folders/{$folder->id}")
        ->assertOk()
        ->assertJsonPath('folder.is_favorited', true);

    $this->deleteJson("/api/folders/{$folder->id}/favorite")->assertOk()
        ->assertJsonPath('is_favorited', false);
});

test('démarrage workflow avec délais par étape', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $project = Project::query()->create([
        'code' => 'PRJ-SLA',
        'name' => 'SLA',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $folder = Folder::query()->create([
        'name' => 'SLA',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'Doc SLA',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('s.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $workflow = Workflow::query()->create([
        'code' => 'WF-SLA',
        'name' => 'SLA flow',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $s1 = $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);
    $s2 = $workflow->steps()->create(['name' => 'S2', 'step_order' => 2, 'is_mandatory' => true]);

    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
        'deadlines' => [
            ['workflow_step_id' => $s1->id, 'amount' => 10, 'unit' => 'hours'],
            ['workflow_step_id' => $s2->id, 'amount' => 1, 'unit' => 'days'],
        ],
    ])->assertOk();

    $v1 = Validation::query()->where('document_id', $docId)->where('workflow_step_id', $s1->id)->first();
    $v2 = Validation::query()->where('document_id', $docId)->where('workflow_step_id', $s2->id)->first();

    expect($v1->sla_hours)->toBe(10)
        ->and($v1->due_at)->not->toBeNull()
        ->and($v2->sla_hours)->toBe(24)
        ->and($v2->due_at)->toBeNull();

    $this->postJson("/api/validations/{$v1->id}/approve", ['comment' => 'OK'])->assertOk();

    expect($v2->fresh()->due_at)->not->toBeNull()
        ->and($v2->fresh()->status)->toBe(ValidationStatus::Pending);
});
