<?php

use App\Enums\DocumentStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function attachRole(User $user, string $slug): User
{
    $user->roles()->sync([Role::query()->where('slug', $slug)->value('id')]);

    return $user;
}

function makeDoc(User $author, Folder $folder, array $extra = []): Document
{
    return Document::query()->create(array_merge([
        'reference' => 'DOC-TEST-'.uniqid(),
        'title' => 'Doc',
        'folder_id' => $folder->id,
        'author_id' => $author->id,
        'owner_id' => $author->id,
        'status' => DocumentStatus::Draft->value,
        'is_editable' => true,
    ], $extra));
}

test('collaborateur n a pas acces au journal', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/activity-logs')->assertForbidden();
});

test('admin voit les kpis globaux', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $folder = Folder::query()->create(['name' => 'Root', 'created_by' => $admin->id]);
    makeDoc($admin, $folder);
    makeDoc($admin, $folder);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'global')
        ->assertJsonPath('dashboard.counts.documents', 2);
});

test('collaborateur voit sa page d accueil ressources', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'home')
        ->assertJsonStructure([
            'dashboard' => [
                'recent_documents',
                'recent_folders',
                'recent_projects',
            ],
        ]);
});

test('responsable ne voit que les documents de son departement', function () {
    $deptA = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $deptB = Department::query()->create(['name' => 'RH', 'code' => 'RH']);

    $manager = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
        'department_id' => $deptA->id,
    ]);
    $deptA->update(['manager_id' => $manager->id]);
    attachRole($manager, 'responsable_departement');

    $folderA = Folder::query()->create([
        'name' => 'A',
        'department_id' => $deptA->id,
        'created_by' => $manager->id,
    ]);
    $folderB = Folder::query()->create([
        'name' => 'B',
        'department_id' => $deptB->id,
        'created_by' => $manager->id,
    ]);

    makeDoc($manager, $folderA, ['department_id' => $deptA->id]);
    makeDoc($manager, $folderB, ['department_id' => $deptB->id]);

    Sanctum::actingAs($manager);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'department')
        ->assertJsonPath('dashboard.counts.documents', 1);
});

test('chef de projet ne voit que ses projets', function () {
    $chef = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);
    attachRole($chef, 'chef_projet');

    $mine = Project::query()->create([
        'name' => 'Mine',
        'code' => 'PRJ-MINE',
        'manager_id' => $chef->id,
    ]);
    $other = Project::query()->create([
        'name' => 'Other',
        'code' => 'PRJ-OTHER',
    ]);

    $folderMine = Folder::query()->create([
        'name' => 'FM',
        'project_id' => $mine->id,
        'created_by' => $chef->id,
    ]);
    $folderOther = Folder::query()->create([
        'name' => 'FO',
        'project_id' => $other->id,
        'created_by' => $chef->id,
    ]);

    makeDoc($chef, $folderMine, ['project_id' => $mine->id]);
    makeDoc($chef, $folderOther, ['project_id' => $other->id]);

    Sanctum::actingAs($chef);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('dashboard.scope.mode', 'project')
        ->assertJsonPath('dashboard.counts.documents', 1)
        ->assertJsonPath('dashboard.counts.projects', 1);
});

test('journal metier est scope pour le responsable', function () {
    $dept = Department::query()->create(['name' => 'Fin', 'code' => 'FIN']);
    $manager = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
        'department_id' => $dept->id,
    ]);
    $dept->update(['manager_id' => $manager->id]);
    attachRole($manager, 'responsable_departement');

    $folder = Folder::query()->create([
        'name' => 'F',
        'department_id' => $dept->id,
        'created_by' => $manager->id,
    ]);
    $doc = makeDoc($manager, $folder, ['department_id' => $dept->id]);

    ActivityLog::query()->create([
        'user_id' => $manager->id,
        'action' => 'documents.update',
        'subject_type' => $doc->getMorphClass(),
        'subject_id' => $doc->id,
        'description' => 'Doc dept',
    ]);
    ActivityLog::query()->create([
        'user_id' => $manager->id,
        'action' => 'settings.update',
        'description' => 'Settings global',
    ]);

    Sanctum::actingAs($manager);

    $response = $this->getJson('/api/activity-logs')->assertOk();
    $descriptions = collect($response->json('data'))->pluck('description');

    expect($descriptions)->toContain('Doc dept')
        ->and($descriptions)->not->toContain('Settings global');
});
