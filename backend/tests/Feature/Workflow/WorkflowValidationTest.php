<?php

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
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

function workflowProjectFolder(): Folder
{
    $admin = adminUser();
    $project = Project::query()->create([
        'code' => 'PRJ-WF-'.uniqid(),
        'name' => 'Projet workflow',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);

    return Folder::query()->create([
        'name' => 'WF',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);
}

function createDocumentForWorkflow(): Document
{
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $folder = workflowProjectFolder();

    $response = test()->post('/api/documents', [
        'title' => 'Doc workflow',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    return Document::query()->findOrFail($response->json('document.id'));
}

function startWorkflow(Document $document, Workflow $workflow, bool $propose = false): void
{
    if ($propose) {
        test()->postJson("/api/documents/{$document->id}/propose")->assertOk();
    }

    test()->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();
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
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'Étape 1', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'Étape 2', 'step_order' => 2]);

    startWorkflow($document, $workflow);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::InValidation)
        ->and(Validation::query()->where('document_id', $document->id)->count())->toBe(2);
});

test('un type de document suggère un workflow sans démarrage auto ni obligation', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-FACTURE',
        'name' => 'Validation facture',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'Comptable', 'step_order' => 1]);

    $type = DocumentType::query()->create([
        'name' => 'Facture',
        'slug' => 'facture',
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    $response = $this->post('/api/documents', [
        'title' => 'Facture client',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value)
        ->assertJsonPath('document.workflow.id', $workflow->id)
        ->assertJsonPath('document.subject_to_workflow', true)
        ->assertJsonPath('document.recommends_workflow', true);

    expect(Validation::query()->where('document_id', $response->json('document.id'))->count())->toBe(0);
});

test('la liste filtre les documents proposés pour l admin', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-LIST',
        'name' => 'List',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $create = $this->post('/api/documents', [
        'title' => 'À proposer',
        'folder_id' => $folder->id,
        'workflow_id' => $workflow->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $documentId = $create->json('document.id');

    $this->getJson('/api/documents?status=propose')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson("/api/documents/{$documentId}/propose")->assertOk();

    $this->getJson('/api/documents?status=propose')
        ->assertOk()
        ->assertJsonPath('data.0.id', $documentId)
        ->assertJsonPath('data.0.status', DocumentStatus::Proposed->value);
});

test('proposition puis démarrage admin pour un document projet soumis à workflow', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-MAQUETTE',
        'name' => 'Validation maquette',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'Chef de projet', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'Direction', 'step_order' => 2]);

    $type = DocumentType::query()->create([
        'name' => 'Maquette',
        'slug' => 'maquette',
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    $create = $this->post('/api/documents', [
        'title' => 'Maquette app',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('mock.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $documentId = $create->json('document.id');

    $this->postJson("/api/documents/{$documentId}/propose")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Proposed->value);

    $this->postJson("/api/documents/{$documentId}/workflow/start")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value)
        ->assertJsonCount(2, 'document.validations');
});

test('un document personnel ne peut pas démarrer de workflow', function () {
    Sanctum::actingAs(adminUser());
    $admin = adminUser();
    $folder = Folder::query()->create(['name' => 'Perso', 'created_by' => $admin->id]);

    $workflow = Workflow::query()->create([
        'code' => 'WF-PERSO',
        'name' => 'Perso',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $document = Document::query()->create([
        'reference' => 'DOC-PERSO-000001',
        'title' => 'Note perso',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'workflow_id' => $workflow->id,
        'status' => DocumentStatus::Draft,
        'is_editable' => false,
    ]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertForbidden();
});

test('approbation successive des étapes valide le document', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-APPROVE',
        'name' => 'Approve all',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);
    $workflow->steps()->create(['name' => 'S2', 'step_order' => 2, 'is_mandatory' => true]);

    startWorkflow($document, $workflow);

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
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'S2', 'step_order' => 2]);

    startWorkflow($document, $workflow);

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
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    startWorkflow($document, $workflow);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    $this->postJson("/api/validations/{$v1->id}/reject", [
        'comment' => 'Non conforme',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);

    expect($v1->fresh()->status)->toBe(ValidationStatus::Rejected);
});

test('demande de correction repropose puis redémarre le workflow', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-CORR',
        'name' => 'Correction',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $type = DocumentType::query()->create([
        'name' => 'Contrat',
        'slug' => 'contrat',
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    $create = $this->post('/api/documents', [
        'title' => 'Contrat',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $document = Document::query()->findOrFail($create->json('document.id'));

    startWorkflow($document, $workflow, propose: true);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    $this->postJson("/api/validations/{$v1->id}/request-correction", [
        'comment' => 'À retravailler',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value);

    $this->postJson("/api/documents/{$document->id}/workflow/restart")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value);

    startWorkflow($document->fresh(), $workflow, propose: true);

    expect($document->fresh()->status)->toBe(DocumentStatus::InValidation);
});

test('publication est autorisée uniquement depuis valide', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $this->postJson("/api/documents/{$document->id}/publish")
        ->assertUnprocessable()
        ->assertJsonPath('errors.document.0', 'Seul un document validé peut être publié.');

    $workflow = Workflow::query()->create([
        'code' => 'WF-PUBLISH',
        'name' => 'Publish flow',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);

    startWorkflow($document, $workflow);

    $validation = Validation::query()->where('document_id', $document->id)->firstOrFail();
    $this->postJson("/api/validations/{$validation->id}/approve")->assertOk();

    $this->postJson("/api/documents/{$document->id}/publish")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Published->value);
});

test('un échec de connexion est journalisé', function () {
    User::factory()->create([
        'email' => 'audit@filebox.test',
        'password' => 'password',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'audit@filebox.test',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'auth.login_failed',
    ]);

    $log = ActivityLog::query()->where('action', 'auth.login_failed')->latest()->first();
    expect($log?->properties['email'] ?? null)->toBe('audit@filebox.test')
        ->and($log?->properties['reason'] ?? null)->toBe('invalid_credentials');
});
