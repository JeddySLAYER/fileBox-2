<?php

use App\Enums\DocumentStatus;
use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AccessExpiringNotification;
use App\Notifications\AccessRevokedNotification;
use App\Notifications\CommentPostedNotification;
use App\Notifications\DocumentArchivedNotification;
use App\Notifications\DocumentProposedAuthorNotification;
use App\Notifications\DocumentProposedNotification;
use App\Notifications\DocumentPublishedNotification;
use App\Notifications\ValidationActionNotification;
use App\Notifications\ValidationReminderNotification;
use App\Models\Validation;
use App\Models\Workflow;
use App\Services\Document\DocumentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

test('révocation d un accès notifie le destinataire', function () {
    Notification::fake();

    $admin = adminUser();
    Sanctum::actingAs($admin);
    $guest = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
    $folder = Folder::query()->create(['name' => 'N', 'created_by' => $admin->id]);

    $accessId = $this->postJson("/api/folders/{$folder->id}/accesses", [
        'user_id' => $guest->id,
        'abilities' => ['view'],
    ])->assertCreated()->json('access.id');

    $this->deleteJson("/api/accesses/{$accessId}")->assertOk();

    Notification::assertSentTo($guest, AccessRevokedNotification::class);
});

test('rappel deadline envoie une notification une seule fois', function () {
    Notification::fake();

    $admin = adminUser();
    $guest = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
    $folder = Folder::query()->create(['name' => 'Deadline', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-DEADLINE-000001',
        'title' => 'Secret',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'is_editable' => false,
    ]);

    $access = Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['view'],
        'ends_at' => now()->addHours(6),
        'granted_by' => $admin->id,
    ]);

    $this->artisan('notifications:access-deadlines')->assertSuccessful();
    Notification::assertSentTo($guest, AccessExpiringNotification::class);
    expect($access->fresh()->expiry_notified_at)->not->toBeNull();

    Notification::fake();
    $this->artisan('notifications:access-deadlines')->assertSuccessful();
    Notification::assertNotSentTo($guest, AccessExpiringNotification::class);
});

test('proposition de document notifie les responsables workflow', function () {
    Notification::fake();

    $manager = adminUser();
    $author = collaboratorUser();

    $project = Project::query()->create([
        'code' => 'PRJ-NOTIF',
        'name' => 'Projet notif',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($author->id);
    $folder = Folder::query()->create([
        'name' => 'Prop',
        'project_id' => $project->id,
        'created_by' => $manager->id,
    ]);

    // L'auteur dépose → proposition auto ; le chef de projet reçoit la notif
    Sanctum::actingAs($author);
    $this->post('/api/documents', [
        'title' => 'À proposer',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', 'propose');

    Notification::assertSentTo($manager, DocumentProposedNotification::class);
    Notification::assertSentToTimes($manager, DocumentProposedNotification::class, 1);
    Notification::assertSentTo($author, DocumentProposedAuthorNotification::class);
    Notification::assertSentToTimes($author, DocumentProposedAuthorNotification::class, 1);
});

test('commentaire notifie auteur et propriétaire du document', function () {
    Notification::fake();

    $owner = adminUser();
    $commenter = collaboratorUser();
    Sanctum::actingAs($owner);

    $folder = Folder::query()->create(['name' => 'C', 'created_by' => $owner->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Doc commenté',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('c.txt', 5, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    Access::query()->create([
        'user_id' => $commenter->id,
        'accessible_type' => 'document',
        'accessible_id' => $docId,
        'abilities' => ['view'],
        'granted_by' => $owner->id,
    ]);

    Sanctum::actingAs($commenter);
    $this->postJson("/api/documents/{$docId}/comments", [
        'content' => 'Hello',
    ])->assertCreated();

    Notification::assertSentTo($owner, CommentPostedNotification::class);
    Notification::assertNotSentTo($commenter, CommentPostedNotification::class);
});

test('réponse notifie l auteur du commentaire parent', function () {
    Notification::fake();

    $owner = adminUser();
    $first = collaboratorUser();
    $replier = collaboratorUser();
    Sanctum::actingAs($owner);

    $folder = Folder::query()->create(['name' => 'R', 'created_by' => $owner->id]);
    $docId = $this->post('/api/documents', [
        'title' => 'Fil',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.txt', 5, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    // Accès lecture pour commenter
    foreach ([$first, $replier] as $user) {
        Access::query()->create([
            'user_id' => $user->id,
            'accessible_type' => 'document',
            'accessible_id' => $docId,
            'abilities' => ['view'],
            'granted_by' => $owner->id,
        ]);
    }

    Sanctum::actingAs($first);
    $parentId = $this->postJson("/api/documents/{$docId}/comments", [
        'content' => 'Parent',
    ])->assertCreated()->json('comment.id');

    Notification::fake();
    Sanctum::actingAs($replier);
    $this->postJson("/api/documents/{$docId}/comments", [
        'content' => 'Réponse',
        'parent_id' => $parentId,
    ])->assertCreated();

    Notification::assertSentTo($first, CommentPostedNotification::class);
    Notification::assertSentTo($owner, CommentPostedNotification::class);
});

test('publication notifie auteur et utilisateurs avec accès', function () {
    Notification::fake();

    $publisher = adminUser();
    $author = collaboratorUser();
    $guest = User::factory()->create(['must_change_password' => false, 'is_active' => true]);

    $folder = Folder::query()->create(['name' => 'Pub', 'created_by' => $publisher->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-PUB-000001',
        'title' => 'Publié',
        'folder_id' => $folder->id,
        'author_id' => $author->id,
        'owner_id' => $author->id,
        'status' => DocumentStatus::Validated,
        'is_editable' => false,
    ]);

    Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['view'],
        'granted_by' => $publisher->id,
    ]);

    app(DocumentService::class)->publish($document, $publisher);

    Notification::assertSentTo($author, DocumentPublishedNotification::class);
    Notification::assertSentTo($guest, DocumentPublishedNotification::class);
    Notification::assertNotSentTo($publisher, DocumentPublishedNotification::class);
});

test('rappels de validation approche puis depassement une seule fois', function () {
    Notification::fake();

    $admin = adminUser();
    $validator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    Sanctum::actingAs($admin);

    $project = Project::query()->create([
        'code' => 'PRJ-REM',
        'name' => 'Rappels',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $folder = Folder::query()->create([
        'name' => 'R',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'À valider',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $workflow = Workflow::query()->create([
        'code' => 'WF-REM',
        'name' => 'Rappels',
        'created_by' => $admin->id,
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

    $this->postJson("/api/documents/{$docId}/propose")->assertOk();
    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    $validation = Validation::query()->where('document_id', $docId)->firstOrFail();

    $this->travelTo($validation->due_at->copy()->subHours(5));
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertNotSentTo($validator, ValidationReminderNotification::class);

    Notification::fake();
    $this->travelTo($validation->due_at->copy()->subHour());
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertSentTo($validator, ValidationReminderNotification::class);

    Notification::fake();
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertNotSentTo($validator, ValidationReminderNotification::class);

    Notification::fake();
    $this->travelTo($validation->due_at->copy()->addMinute());
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertSentTo($validator, ValidationReminderNotification::class);
    Notification::assertSentTo($admin, ValidationReminderNotification::class);

    Notification::fake();
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertNotSentTo($validator, ValidationReminderNotification::class);
});

test('un refus de validation notifie auteur, chef de projet et admin', function () {
    Notification::fake();

    $admin = adminUser();
    $manager = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $author = collaboratorUser();
    $validator = collaboratorUser();

    Sanctum::actingAs($admin);
    $project = Project::query()->create([
        'code' => 'PRJ-REJ',
        'name' => 'Refus',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);
    $project->members()->attach($author->id);
    $folder = Folder::query()->create([
        'name' => 'R',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($author);
    $docId = $this->post('/api/documents', [
        'title' => 'À rejeter',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.status', 'propose')
        ->json('document.id');

    Sanctum::actingAs($admin);
    $workflow = Workflow::query()->create([
        'code' => 'WF-REJ',
        'name' => 'Refus',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'Validation 1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
    ]);

    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    Notification::fake();

    Sanctum::actingAs($validator);
    $validation = Validation::query()->where('document_id', $docId)->firstOrFail();
    $this->postJson("/api/validations/{$validation->id}/reject", [
        'comment' => 'Non conforme',
    ])->assertOk();

    Notification::assertSentTo($author, ValidationActionNotification::class);
    Notification::assertSentTo($manager, ValidationActionNotification::class);
    Notification::assertSentTo($admin, ValidationActionNotification::class);
    Notification::assertNotSentTo($validator, ValidationActionNotification::class);
});

test('un depassement de delai notifie meme sans case a cocher', function () {
    Notification::fake();

    $admin = adminUser();
    $validator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    Sanctum::actingAs($admin);

    $project = Project::query()->create([
        'code' => 'PRJ-OV',
        'name' => 'Overdue',
        'manager_id' => $admin->id,
        'status' => 'active',
    ]);
    $folder = Folder::query()->create([
        'name' => 'O',
        'project_id' => $project->id,
        'created_by' => $admin->id,
    ]);

    $docId = $this->post('/api/documents', [
        'title' => 'En retard',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('o.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $workflow = Workflow::query()->create([
        'code' => 'WF-OV',
        'name' => 'Overdue',
        'created_by' => $admin->id,
        'is_active' => true,
    ]);
    $workflow->steps()->create([
        'name' => 'Validation 1',
        'step_order' => 1,
        'responsible_user_id' => $validator->id,
        'is_mandatory' => true,
        'duration_hours' => 2,
        'remind_on_overdue' => false,
    ]);

    $this->postJson("/api/documents/{$docId}/propose")->assertOk();
    $this->postJson("/api/documents/{$docId}/workflow/start", [
        'workflow_id' => $workflow->id,
    ])->assertOk();

    $validation = Validation::query()->where('document_id', $docId)->firstOrFail();
    $validation->update(['remind_on_overdue' => false]);

    Notification::fake();
    $this->travelTo($validation->due_at->copy()->addMinute());
    $this->artisan('notifications:validation-reminders')->assertSuccessful();
    Notification::assertSentTo($validator, ValidationReminderNotification::class);
});

test('archivage notifie auteur et propriétaire hors acteur', function () {
    Notification::fake();

    $admin = adminUser();
    $author = collaboratorUser();
    Sanctum::actingAs($admin);

    $folder = Folder::query()->create(['name' => 'Arch', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-ARCH-000001',
        'title' => 'À archiver',
        'folder_id' => $folder->id,
        'author_id' => $author->id,
        'owner_id' => $author->id,
        'status' => DocumentStatus::Draft,
        'is_editable' => false,
    ]);

    app(DocumentService::class)->archive($document);

    Notification::assertSentTo($author, DocumentArchivedNotification::class);
    Notification::assertNotSentTo($admin, DocumentArchivedNotification::class);
});
