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

    $document = Document::query()->findOrFail($response->json('document.id'));

    // Les managers publient en « valide » sans circuit ; les tests workflow repartent du brouillon.
    if ($document->status === DocumentStatus::Validated) {
        $document->status = DocumentStatus::Draft;
        $document->save();
    }

    return $document->fresh();
}

function startWorkflow(Document $document, Workflow $workflow, bool $propose = true): void
{
    $document->refresh();

    if ($document->status === DocumentStatus::InValidation) {
        return;
    }

    if ($propose && $document->status === DocumentStatus::Draft) {
        test()->postJson("/api/documents/{$document->id}/propose")->assertOk();
        $document->refresh();
    }

    test()->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();
}

test('un admin peut créer un workflow avec étapes', function () {
    Sanctum::actingAs(adminUser());
    $userA = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $userB = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $this->postJson('/api/workflows', [
        'name' => 'Validation contrat',
        'code' => 'WF-VALIDATION-CONTRAT',
        'steps' => [
            ['name' => 'Validation 1', 'step_order' => 1, 'responsible_user_id' => $userA->id],
            ['name' => 'Validation 2', 'step_order' => 2, 'responsible_user_id' => $userB->id],
        ],
    ])->assertCreated()
        ->assertJsonPath('workflow.code', 'WF-VALIDATION-CONTRAT')
        ->assertJsonCount(2, 'workflow.steps');
});

test('un workflow peut assigner un utilisateur responsable par étape', function () {
    Sanctum::actingAs(adminUser());
    $validator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $this->postJson('/api/workflows', [
        'name' => 'Validation nominative',
        'code' => 'WF-USER-STEP',
        'steps' => [
            [
                'step_order' => 1,
                'responsible_user_id' => $validator->id,
            ],
        ],
    ])->assertCreated()
        ->assertJsonPath('workflow.steps.0.responsible_user.id', $validator->id)
        ->assertJsonPath('workflow.steps.0.name', 'Validation 1');
});

test('un utilisateur ne peut pas être assigné deux fois dans un workflow', function () {
    Sanctum::actingAs(adminUser());
    $validator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $this->postJson('/api/workflows', [
        'name' => 'Doublon',
        'code' => 'WF-DUP-USER',
        'steps' => [
            ['responsible_user_id' => $validator->id],
            ['responsible_user_id' => $validator->id],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['steps']);
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
        'requires_workflow' => false,
    ]);

    $response = $this->post('/api/documents', [
        'title' => 'Facture client',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value)
        ->assertJsonPath('document.workflow.id', $workflow->id)
        ->assertJsonPath('document.subject_to_workflow', true)
        ->assertJsonPath('document.recommends_workflow', true);

    expect(Validation::query()->where('document_id', $response->json('document.id'))->count())->toBe(0);
});

test('un type avec workflow obligatoire démarre le circuit dès le dépôt', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-OBLIG',
        'name' => 'Circuit obligatoire',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'Étape 1', 'step_order' => 1]);
    $workflow->steps()->create(['name' => 'Étape 2', 'step_order' => 2]);

    $type = DocumentType::query()->create([
        'name' => 'Contrat',
        'slug' => 'contrat-oblig',
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    $response = $this->post('/api/documents', [
        'title' => 'Contrat auto',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('contrat.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value)
        ->assertJsonPath('document.workflow.id', $workflow->id);

    expect(Validation::query()->where('document_id', $response->json('document.id'))->count())->toBe(2);
});

test('un collaborateur qui dépose dans un projet crée une proposition', function () {
    $manager = adminUser();
    $collab = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-PROP-'.uniqid(),
        'name' => 'Projet propositions',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($collab->id);
    $folder = Folder::query()->create([
        'name' => 'Dépôts',
        'project_id' => $project->id,
        'created_by' => $manager->id,
    ]);

    Sanctum::actingAs($collab);
    $this->post('/api/documents', [
        'title' => 'Dépôt collab',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Proposed->value);
});

test('admin chef et responsable deposent sans validation sauf type obligatoire', function () {
    $admin = adminUser();
    $chef = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $chef->roles()->attach(Role::query()->where('slug', 'chef_projet')->firstOrFail());
    $responsable = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $responsable->roles()->attach(Role::query()->where('slug', 'responsable_departement')->firstOrFail());

    $project = Project::query()->create([
        'code' => 'PRJ-MGR-'.uniqid(),
        'name' => 'Projet managers',
        'manager_id' => $chef->id,
        'status' => 'active',
    ]);
    $project->members()->attach([$chef->id, $responsable->id]);
    $folder = Folder::query()->create([
        'name' => 'Espace',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);
    $this->post('/api/documents', [
        'title' => 'Dépôt admin',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);

    Sanctum::actingAs($chef);
    $this->post('/api/documents', [
        'title' => 'Dépôt chef',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);

    Sanctum::actingAs($responsable);
    $this->post('/api/documents', [
        'title' => 'Dépôt responsable',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);

    $workflow = Workflow::query()->create([
        'code' => 'WF-MGR-OBLIG',
        'name' => 'Obligatoire managers',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $admin->id,
        'is_mandatory' => true,
    ]);
    $type = DocumentType::query()->create([
        'name' => 'Contrat mgr',
        'slug' => 'contrat-mgr-'.uniqid(),
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    Sanctum::actingAs($chef);
    $this->post('/api/documents', [
        'title' => 'Contrat obligatoire',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ctr.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value);
});

test('le chef peut accepter une proposition sans circuit', function () {
    $manager = adminUser();
    $collab = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-ACC-'.uniqid(),
        'name' => 'Projet accept',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($collab->id);
    $folder = Folder::query()->create([
        'name' => 'Acc',
        'project_id' => $project->id,
        'created_by' => $manager->id,
    ]);

    Sanctum::actingAs($collab);
    $docId = $this->post('/api/documents', [
        'title' => 'À accepter',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    Sanctum::actingAs($manager);
    $this->postJson("/api/documents/{$docId}/accept")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);
});

test('accepter une proposition est refusé si le type exige un workflow', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-NO-ACC',
        'name' => 'No accept',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1]);

    $type = DocumentType::query()->create([
        'name' => 'Type obligatoire accept',
        'slug' => 'type-no-accept',
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    // Simuler une proposition résiduelle (ex. workflow indisponible au dépôt)
    $document = Document::query()->create([
        'reference' => 'DOC-NOACC-000001',
        'title' => 'Ne pas accepter',
        'folder_id' => $folder->id,
        'project_id' => $folder->project_id,
        'document_type_id' => $type->id,
        'workflow_id' => $workflow->id,
        'author_id' => adminUser()->id,
        'owner_id' => adminUser()->id,
        'status' => DocumentStatus::Proposed,
        'is_editable' => false,
    ]);

    $this->postJson("/api/documents/{$document->id}/accept")
        ->assertForbidden();
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
        'requires_workflow' => false,
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
    $admin = adminUser();
    $step1 = collaboratorUser();
    $step2 = collaboratorUser();
    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-APPROVE',
        'name' => 'Approve all',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'is_mandatory' => true,
        'responsible_user_id' => $step1->id,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'is_mandatory' => true,
        'responsible_user_id' => $step2->id,
    ]);

    startWorkflow($document, $workflow);

    $v1 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();

    Sanctum::actingAs($admin);
    $this->postJson("/api/validations/{$v1->id}/approve", [
        'comment' => 'OK étape 1',
    ])->assertForbidden();

    Sanctum::actingAs($step1);
    $this->postJson("/api/validations/{$v1->id}/approve", [
        'comment' => 'OK étape 1',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value);

    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    Sanctum::actingAs($step2);
    $this->postJson("/api/validations/{$v2->id}/approve")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);
});

test('on ne peut pas valider une étape hors ordre', function () {
    $admin = adminUser();
    $step1 = collaboratorUser();
    $step2 = collaboratorUser();
    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-ORDER',
        'name' => 'Order',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $step1->id,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'responsible_user_id' => $step2->id,
    ]);

    startWorkflow($document, $workflow);

    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    Sanctum::actingAs($step2);
    $this->postJson("/api/validations/{$v2->id}/approve")
        ->assertUnprocessable();
});

test('un rejet met le document au statut rejeté', function () {
    $admin = adminUser();
    $validator = collaboratorUser();
    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-REJECT',
        'name' => 'Reject',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
    ]);

    startWorkflow($document, $workflow);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    Sanctum::actingAs($validator);
    $this->postJson("/api/validations/{$v1->id}/reject", [
        'comment' => 'Non conforme',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);

    expect($v1->fresh()->status)->toBe(ValidationStatus::Rejected);
});

test('demande de correction repropose puis redémarre le workflow', function () {
    $admin = adminUser();
    $validator = collaboratorUser();
    Sanctum::actingAs($admin);
    $folder = workflowProjectFolder();

    $workflow = Workflow::query()->create([
        'code' => 'WF-CORR',
        'name' => 'Correction',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
    ]);

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

    Sanctum::actingAs($validator);
    $this->postJson("/api/validations/{$v1->id}/request-correction", [
        'comment' => 'À retravailler',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value);

    Sanctum::actingAs($admin);
    $this->postJson("/api/documents/{$document->id}/workflow/restart")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Draft->value);

    startWorkflow($document->fresh(), $workflow, propose: true);

    expect($document->fresh()->status)->toBe(DocumentStatus::InValidation);
});

test('publication est autorisée uniquement depuis valide', function () {
    $admin = adminUser();
    $validator = collaboratorUser();
    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $this->postJson("/api/documents/{$document->id}/publish")
        ->assertUnprocessable()
        ->assertJsonPath('errors.document.0', 'Seul un document validé peut être publié.');

    $workflow = Workflow::query()->create([
        'code' => 'WF-PUBLISH',
        'name' => 'Publish flow',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'is_mandatory' => true,
        'responsible_user_id' => $validator->id,
    ]);

    startWorkflow($document, $workflow);

    $validation = Validation::query()->where('document_id', $document->id)->firstOrFail();
    Sanctum::actingAs($validator);
    $this->postJson("/api/validations/{$validation->id}/approve")->assertOk();

    Sanctum::actingAs($admin);
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

test('on ne peut pas modifier ni supprimer un workflow en cours de validation', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();
    $userA = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $workflow = Workflow::query()->create([
        'code' => 'WF-INUSE',
        'name' => 'En cours',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'Validation 1',
        'step_order' => 1,
        'responsible_user_id' => $userA->id,
        'is_mandatory' => true,
    ]);

    startWorkflow($document, $workflow);

    $this->putJson("/api/workflows/{$workflow->id}", [
        'name' => 'Renommé',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['workflow']);

    $this->deleteJson("/api/workflows/{$workflow->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workflow']);
});

test('supprimer un workflow non en cours détache les documents et types', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-UNLINK',
        'name' => 'À détacher',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);

    $document->update(['workflow_id' => $workflow->id]);
    $type = DocumentType::query()->create([
        'name' => 'Type lié',
        'slug' => 'type-lie-'.uniqid(),
        'default_workflow_id' => $workflow->id,
    ]);

    $this->putJson("/api/workflows/{$workflow->id}", [
        'name' => 'Renommé libre',
    ])->assertOk();

    $this->deleteJson("/api/workflows/{$workflow->id}")->assertOk();

    expect($document->fresh()->workflow_id)->toBeNull()
        ->and($type->fresh()->default_workflow_id)->toBeNull()
        ->and(Workflow::query()->find($workflow->id))->toBeNull();
});

test('un document brouillon ne démarre un workflow qu après proposition', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();
    $workflow = Workflow::query()->create([
        'code' => 'WF-NEED-PROPOSE',
        'name' => 'Propose d’abord',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->postJson("/api/documents/{$document->id}/propose")->assertOk();

    $this->postJson("/api/documents/{$document->id}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value);
});

test('un type qui exige une validation démarre sans proposition manuelle', function () {
    Sanctum::actingAs(adminUser());
    $folder = workflowProjectFolder();
    $workflow = Workflow::query()->create([
        'code' => 'WF-OBLIG-MANUAL',
        'name' => 'Obligatoire',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create(['name' => 'S1', 'step_order' => 1, 'is_mandatory' => true]);

    $type = DocumentType::query()->create([
        'name' => 'Contrat obligatoire',
        'slug' => 'contrat-oblig-'.uniqid(),
        'default_workflow_id' => $workflow->id,
        'requires_workflow' => true,
    ]);

    $this->post('/api/documents', [
        'title' => 'Contrat',
        'folder_id' => $folder->id,
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', DocumentStatus::InValidation->value)
        ->assertJsonPath('document.workflow.id', $workflow->id);
});

test('création d un workflow avec durée et rappel par étape', function () {
    Sanctum::actingAs(adminUser());
    $userA = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $this->postJson('/api/workflows', [
        'name' => 'Avec SLA',
        'code' => 'WF-SLA-CREATE',
        'steps' => [
            [
                'responsible_user_id' => $userA->id,
                'duration_amount' => 2,
                'duration_unit' => 'days',
                'reminder_amount' => 4,
                'reminder_unit' => 'hours',
                'remind_on_overdue' => true,
            ],
        ],
    ])->assertCreated()
        ->assertJsonPath('workflow.steps.0.duration_hours', 48)
        ->assertJsonPath('workflow.steps.0.reminder_hours_before', 4)
        ->assertJsonPath('workflow.steps.0.remind_on_overdue', true);
});

test('un rappel ne peut pas être égal ou supérieur à la durée de l étape', function () {
    Sanctum::actingAs(adminUser());
    $userA = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $this->postJson('/api/workflows', [
        'name' => 'SLA invalide',
        'steps' => [
            [
                'responsible_user_id' => $userA->id,
                'duration_amount' => 2,
                'duration_unit' => 'hours',
                'reminder_amount' => 2,
                'reminder_unit' => 'hours',
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['steps.0.reminder_amount']);
});

test('démarrage d un workflow reprend la durée définie sur l étape', function () {
    Sanctum::actingAs(adminUser());
    $document = createDocumentForWorkflow();
    $validator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

    $workflow = Workflow::query()->create([
        'code' => 'WF-SLA-DEFAULT',
        'name' => 'SLA défaut',
        'created_by' => adminUser()->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'Validation 1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
        'duration_hours' => 10,
        'reminder_hours_before' => 2,
        'remind_on_overdue' => true,
    ]);

    startWorkflow($document, $workflow);

    $validation = Validation::query()->where('document_id', $document->id)->first();
    expect($validation->sla_hours)->toBe(10)
        ->and($validation->reminder_hours_before)->toBe(2)
        ->and($validation->due_at)->not->toBeNull();
});

test('la boite de reception n expose que l etape courante', function () {
    $admin = adminUser();
    $step1 = collaboratorUser();
    $step2 = collaboratorUser();
    $outsider = collaboratorUser();

    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-INBOX',
        'name' => 'Inbox sequential',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $step1->id,
        'is_mandatory' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'responsible_user_id' => $step2->id,
        'is_mandatory' => true,
    ]);

    startWorkflow($document, $workflow);

    $v1 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();
    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    Sanctum::actingAs($step1);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v1->id)
        ->assertJsonPath('data.0.document.id', $document->id);
    $this->getJson("/api/documents/{$document->id}")->assertOk();

    Sanctum::actingAs($step2);
    $this->getJson('/api/validations/inbox')->assertOk()->assertJsonCount(0, 'data');
    // Pas encore son étape : le document n’est pas visible.
    $this->getJson("/api/documents/{$document->id}")->assertForbidden();
    $this->getJson("/api/documents/{$document->id}/validations")->assertForbidden();

    Sanctum::actingAs($outsider);
    $this->getJson('/api/validations/inbox')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/documents/{$document->id}")->assertForbidden();

    Sanctum::actingAs($admin);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v1->id);
    $this->getJson("/api/documents/{$document->id}/validations")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    Sanctum::actingAs($step1);
    $this->postJson("/api/validations/{$v1->id}/approve", [
        'comment' => 'OK étape 1',
    ])->assertOk();

    Sanctum::actingAs($step1);
    $this->getJson('/api/validations/inbox')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($admin);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v2->id);

    Sanctum::actingAs($step2);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v2->id);
    $this->getJson("/api/documents/{$document->id}")->assertOk();
});

test('un validateur ne voit que son etape sur le document, l admin voit tout le circuit', function () {
    $admin = adminUser();
    $step1 = collaboratorUser();
    $step2 = collaboratorUser();

    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-STEPS-SCOPE',
        'name' => 'Scope étapes',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $step1->id,
        'is_mandatory' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'responsible_user_id' => $step2->id,
        'is_mandatory' => true,
    ]);

    startWorkflow($document, $workflow);

    Sanctum::actingAs($admin);
    $this->getJson("/api/documents/{$document->id}/validations")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    Sanctum::actingAs($step1);
    $this->getJson("/api/documents/{$document->id}/validations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.workflow_step.step_order', 1);

    Sanctum::actingAs($step2);
    $this->getJson("/api/documents/{$document->id}/validations")->assertForbidden();

    Sanctum::actingAs($step1);
    $v1 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();
    $this->postJson("/api/validations/{$v1->id}/approve", ['comment' => 'OK'])->assertOk();

    Sanctum::actingAs($step2);
    $this->getJson("/api/documents/{$document->id}/validations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.workflow_step.step_order', 2);
});

test('auteur chef et admin suivent tout le circuit sans pouvoir valider a la place', function () {
    $admin = adminUser();
    $manager = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $manager->roles()->attach(\App\Models\Role::query()->where('slug', 'chef_projet')->firstOrFail());
    $author = collaboratorUser();
    $validator = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-SUIVI-'.uniqid(),
        'name' => 'Suivi circuit',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($author->id);
    $folder = Folder::query()->create([
        'name' => 'Suivi',
        'project_id' => $project->id,
        'created_by' => $manager->id,
    ]);

    Sanctum::actingAs($author);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc suivi',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    Sanctum::actingAs($admin);
    $workflow = Workflow::query()->create([
        'code' => 'WF-SUIVI',
        'name' => 'Suivi',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
    ]);
    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    foreach ([$author, $manager, $admin] as $follower) {
        Sanctum::actingAs($follower);
        $this->getJson("/api/documents/{$docId}")->assertOk();
        $this->getJson("/api/documents/{$docId}/validations")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    $v1 = Validation::query()
        ->where('document_id', $docId)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();

    Sanctum::actingAs($author);
    $this->postJson("/api/validations/{$v1->id}/approve", ['comment' => 'non'])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson("/api/validations/{$v1->id}/approve", ['comment' => 'non'])->assertForbidden();
});

test('une proposition n apparait pas dans le dossier pour les collaborateurs mais oui pour le chef', function () {
    $manager = adminUser();
    $collab = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-HIDE-'.uniqid(),
        'name' => 'Projet hide propose',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($collab->id);
    $folder = Folder::query()->create([
        'name' => 'Espace',
        'project_id' => $project->id,
        'created_by' => $manager->id,
    ]);

    Sanctum::actingAs($collab);
    $docId = $this->post('/api/documents', [
        'title' => 'Caché du dossier',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('h.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    // Collaborateur membre : pas dans le dossier (hors auteur — ici l’auteur est collab, il peut le voir via author?)
    // L’auteur voit encore sa propre proposition via scope author ; on vérifie le chef et la liste Validations.
    Sanctum::actingAs($manager);
    $this->getJson("/api/documents?folder_id={$folder->id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $docId);

    $this->getJson('/api/documents?status=propose')
        ->assertOk()
        ->assertJsonPath('data.0.id', $docId);

    // Un autre collaborateur du projet ne voit pas la proposition dans le dossier
    $other = collaboratorUser();
    $project->members()->attach($other->id);
    Sanctum::actingAs($other);
    $this->getJson("/api/documents?folder_id={$folder->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson("/api/documents/{$docId}")->assertForbidden();
});

test('un document en validation n apparait pour tout le monde qu apres validation complete', function () {
    $admin = adminUser();
    $validator = collaboratorUser();
    $member = collaboratorUser();
    Sanctum::actingAs($admin);
    $folder = workflowProjectFolder();
    $folder->project->members()->attach([$validator->id, $member->id]);

    $workflow = Workflow::query()->create([
        'code' => 'WF-HIDE-VAL',
        'name' => 'Hide until validated',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'En circuit',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    $this->postJson("/api/documents/{$docId}/propose")->assertOk();
    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    // Admin / chef : voient le doc en circuit
    $this->getJson("/api/documents?folder_id={$folder->id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $docId);

    // Membre projet non validateur : ni liste ni fiche
    Sanctum::actingAs($member);
    $this->getJson("/api/documents?folder_id={$folder->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson("/api/documents/{$docId}")->assertForbidden();

    // Validateur de l’étape courante : voit la fiche
    Sanctum::actingAs($validator);
    $this->getJson("/api/documents/{$docId}")->assertOk();

    $validation = Validation::query()->where('document_id', $docId)->firstOrFail();
    $this->postJson("/api/validations/{$validation->id}/approve")->assertOk();

    // Après validation : visible pour tout le monde dans l’espace
    Sanctum::actingAs($member);
    $this->getJson("/api/documents?folder_id={$folder->id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $docId)
        ->assertJsonPath('data.0.status', DocumentStatus::Validated->value);
});

test('apres une etape approuvee le validateur suivant voit le document et peut valider', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $admin = adminUser();
    $step1 = collaboratorUser();
    $step2 = collaboratorUser();

    Sanctum::actingAs($admin);
    $document = createDocumentForWorkflow();

    $workflow = Workflow::query()->create([
        'code' => 'WF-HANDOFF',
        'name' => 'Handoff',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $step1->id,
        'is_mandatory' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S2',
        'step_order' => 2,
        'responsible_user_id' => $step2->id,
        'is_mandatory' => true,
    ]);

    startWorkflow($document, $workflow);

    $v1 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 1))
        ->firstOrFail();
    $v2 = Validation::query()
        ->where('document_id', $document->id)
        ->whereHas('workflowStep', fn ($q) => $q->where('step_order', 2))
        ->firstOrFail();

    Sanctum::actingAs($step2);
    $this->getJson('/api/validations/inbox')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/documents/{$document->id}")->assertForbidden();

    \Illuminate\Support\Facades\Notification::fake();
    Sanctum::actingAs($step1);
    $this->postJson("/api/validations/{$v1->id}/approve", ['comment' => 'OK'])->assertOk();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $step2,
        \App\Notifications\ValidationActionNotification::class,
        function ($notification) use ($v2, $step2) {
            $payload = $notification->toArray($step2);

            return ($payload['type'] ?? null) === 'validation.started'
                && (int) ($payload['validation_id'] ?? 0) === (int) $v2->id;
        }
    );

    // L’auteur (admin créateur du doc) est informé de l’avancement
    \Illuminate\Support\Facades\Notification::assertSentTo(
        $admin,
        \App\Notifications\ValidationActionNotification::class,
        function ($notification) use ($admin) {
            return ($notification->toArray($admin)['type'] ?? null) === 'validation.approved';
        }
    );

    Sanctum::actingAs($step2);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v2->id);
    $this->getJson("/api/documents/{$document->id}")->assertOk();
    $this->postJson("/api/validations/{$v2->id}/approve")->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Validated->value);
});

test('proposeur et chef projet voient l etape courante dans la boite a suivre', function () {
    $admin = adminUser();
    $chef = collaboratorUser();
    $author = collaboratorUser();
    $step1 = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-FOLLOW-'.uniqid(),
        'name' => 'Projet suivi',
        'manager_id' => $chef->id,
        'status' => 'active',
    ]);
    $project->members()->attach([$author->id, $chef->id]);
    $folder = Folder::query()->create([
        'name' => 'Suivi',
        'project_id' => $project->id,
        'created_by' => $chef->id,
    ]);

    Sanctum::actingAs($author);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc suivi',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('document.id');

    $document = Document::query()->findOrFail($docId);

    Sanctum::actingAs($admin);
    $workflow = Workflow::query()->create([
        'code' => 'WF-FOLLOW',
        'name' => 'Follow',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $step1->id,
        'is_mandatory' => true,
    ]);

    startWorkflow($document, $workflow, propose: false);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    Sanctum::actingAs($author);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v1->id);

    Sanctum::actingAs($chef);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v1->id);

    Sanctum::actingAs($admin);
    $this->getJson('/api/validations/inbox')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $v1->id);
});

test('apres rejet le proposeur peut deposer une version et reproposer', function () {
    $admin = adminUser();
    $author = collaboratorUser();
    $validator = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-REJ-'.uniqid(),
        'name' => 'Projet rejet',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $project->members()->attach($author->id);
    $folder = Folder::query()->create([
        'name' => 'Rejet',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($author);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc rejet',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('document.id');

    $document = Document::query()->findOrFail($docId);

    Sanctum::actingAs($admin);
    $workflow = Workflow::query()->create([
        'code' => 'WF-REJ-VER',
        'name' => 'Reject version',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'S1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
    ]);
    startWorkflow($document, $workflow, propose: false);

    $v1 = Validation::query()->where('document_id', $document->id)->firstOrFail();

    Sanctum::actingAs($validator);
    $this->postJson("/api/validations/{$v1->id}/reject", [
        'comment' => 'Non conforme',
    ])->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);

    Sanctum::actingAs($author);
    $this->getJson("/api/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('document.can_propose', true)
        ->assertJsonPath('document.status', DocumentStatus::Rejected->value);

    $this->post("/api/documents/{$document->id}/versions", [
        'file' => UploadedFile::fake()->create('r2.pdf', 12, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect(Document::query()->findOrFail($document->id)->versions()->count())->toBe(2);

    $this->postJson("/api/documents/{$document->id}/propose")
        ->assertOk()
        ->assertJsonPath('document.status', DocumentStatus::Proposed->value);
});
