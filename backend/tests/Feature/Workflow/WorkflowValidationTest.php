<?php

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Role;
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

function createDocumentForWorkflow(): Document
{
    $admin = adminUser();
    $folder = Folder::query()->create(['name' => 'WF', 'created_by' => $admin->id]);

    $response = test()->actingAs($admin)->post('/api/documents', [
        'title' => 'Doc workflow',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    return Document::query()->findOrFail($response->json('document.id'));
}

test('un admin peut créer un workflow avec étapes', function () {
    Sanctum::actingAs(adminUser());
    $roleId = Role::query()->where('slug', 'direction')->value('id');

    $this->postJson('/api/workflows', [
        'name' => 'Validation contrat',
        'code' => 'WF-VALIDATION-CONTRAT',
        'steps' => [
            ['name' => 'Relecture', 'step_order' => 1, 'responsible_role_id' => $roleId],
            ['name' => 'Validation finale', 'step_order' => 2, 'responsible_role_id' => $roleId],
        ],
    ])->assertCreated()
        ->assertJsonPath('workflow.code', 'WF-VALIDATION-CONTRAT')
        ->assertJsonCount(2, 'workflow.steps');
});

test('démarrage d un workflow met le document en validation', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-TEST',
        'name' => 'Test',
        'created_by' => adminUser()->id,
    ]);
    $workflow->steps()->create(['name' => 'Étape 1', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'Étape 2', 'step_order' => 2]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value)
        ->assertJsonCount(2, 'document.validations');

    expect(Validation::query()->where('document_id', $document->id)->count())->toBe(2);
});

test('approbation successive des étapes valide le document', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-APPROVE',
        'name' => 'Approve all',
        'created_by' => adminUser()->id,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);
    $workflow->steps()->create(['name' => 'S2', 'step_order' => 2, 'is_mandatory' => true]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    $v1 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();

    $this->postJson("/api/validations/{$v1->id}/approve", [
        'comment' => 'OK étape 1',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value);

    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    $this->postJson("/api/validations/{$v2->id}/approve")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);
});

test('on ne peut pas valider une étape hors ordre', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-ORDER',
        'name' => 'Order',
        'created_by' => adminUser()->id,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'S2', 'step_order' => 2]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ]);

    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    $this->postJson("/api/validations/{$v2->id}/approve")
        ->assertUnprocessable();
});

test('un rejet met le document au statut rejeté', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-REJECT',
        'name' => 'Reject',
        'created_by' => adminUser()->id,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ]);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    $this->postJson("/api/validations/{$v1->id}/reject", [
        'comment' => 'Non conforme',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);

    expect($v1->fresh()->status)->toBe(ValidationStatus::Rejected);
});

test('demande de correction puis relance du workflow', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-CORR',
        'name' => 'Correction',
        'created_by' => adminUser()->id,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ]);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    $this->postJson("/api/validations/{$v1->id}/request-correction", [
        'comment' => 'À retravailler',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value);

    $this->postJson("/api/documents/{$document->id}/workflow/restart")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value);
});
