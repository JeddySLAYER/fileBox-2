<?php

use App\Models\Department;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function spaceCollaborator(?int $departmentId = null): User
{
    $user = collaboratorUser();
    if ($departmentId) {
        $user->update(['department_id' => $departmentId]);
    }

    return $user->fresh();
}

test('un collaborateur RH ne voit pas les dossiers commerciaux', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $com = Department::query()->create(['name' => 'Commercial', 'code' => 'COM']);

    $rhUser = spaceCollaborator($rh->id);
    $comUser = spaceCollaborator($com->id);

    $rhFolder = Folder::query()->create([
        'name' => 'Dossiers RH',
        'department_id' => $rh->id,
        'created_by' => $rhUser->id,
    ]);
    $comFolder = Folder::query()->create([
        'name' => 'Contrats clients',
        'department_id' => $com->id,
        'created_by' => $comUser->id,
    ]);

    Sanctum::actingAs($rhUser);

    $names = collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toContain('Dossiers RH')
        ->and($names)->not->toContain('Contrats clients');

    $this->getJson("/api/folders/{$rhFolder->id}")->assertOk();
    $this->getJson("/api/folders/{$comFolder->id}")->assertForbidden();
    $this->getJson("/api/folders?parent_id={$comFolder->id}")->assertForbidden();
});

test('un collaborateur ne voit que les espaces projet dont il est membre', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);
    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);

    $mine = $this->postJson('/api/projects', [
        'name' => 'Mon projet',
        'code' => 'PRJ-MINE-VIS',
        'department_ids' => [$dept->id],
    ])->assertCreated()->json('project');

    $other = $this->postJson('/api/projects', [
        'name' => 'Autre projet',
        'code' => 'PRJ-OTHER-VIS',
        'department_ids' => [$dept->id],
    ])->assertCreated()->json('project');

    $member = spaceCollaborator($dept->id);
    $this->putJson("/api/projects/{$mine['id']}/members", [
        'member_ids' => [$member->id],
    ])->assertOk();

    Sanctum::actingAs($member);

    $roots = collect($this->getJson('/api/folders?project_roots=1')->assertOk()->json('data'))
        ->pluck('name')
        ->all();

    expect($roots)->toContain('Mon projet')
        ->and($roots)->not->toContain('Autre projet');

    $this->getJson("/api/folders/{$mine['root_folder_id']}")->assertOk();
    $this->getJson("/api/folders/{$other['root_folder_id']}")->assertForbidden();
});

test('un dossier personnel n est visible que de son auteur', function () {
    $alice = spaceCollaborator();
    $bob = spaceCollaborator();

    Sanctum::actingAs($alice);
    $folderId = $this->postJson('/api/folders', ['name' => 'Notes Alice'])
        ->assertCreated()
        ->json('folder.id');

    $names = collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toContain('Notes Alice');

    Sanctum::actingAs($bob);
    $names = collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('Notes Alice');
    $this->getJson("/api/folders/{$folderId}")->assertForbidden();
});

test('un collaborateur ne voit pas les documents d un autre espace', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $com = Department::query()->create(['name' => 'Commercial', 'code' => 'COM']);
    $rhUser = spaceCollaborator($rh->id);
    $comUser = spaceCollaborator($com->id);

    $comFolder = Folder::query()->create([
        'name' => 'Offres',
        'department_id' => $com->id,
        'created_by' => $comUser->id,
    ]);

    Sanctum::actingAs($comUser);
    $docId = $this->post('/api/documents', [
        'title' => 'Contrat secret',
        'folder_id' => $comFolder->id,
        'file' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    Sanctum::actingAs($rhUser);
    $this->getJson("/api/documents/{$docId}")->assertForbidden();
    $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/search?q=Contrat')->assertOk()->assertJsonCount(0, 'documents.data');
    $this->post('/api/documents', [
        'title' => 'Intrusion',
        'folder_id' => $comFolder->id,
        'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertForbidden();
});

test('admin et direction voient tous les espaces', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $rhUser = spaceCollaborator($rh->id);
    Folder::query()->create([
        'name' => 'Paie',
        'department_id' => $rh->id,
        'created_by' => $rhUser->id,
    ]);

    Sanctum::actingAs(adminUser());
    expect(collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all())
        ->toContain('Paie');

    $direction = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
    $direction->roles()->attach(
        \App\Models\Role::query()->where('slug', 'direction')->firstOrFail()
    );
    Sanctum::actingAs($direction);
    expect(collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all())
        ->toContain('Paie');
});

test('espace prive invisible hors proprietaire et partage meme pour admin', function () {
    $alice = spaceCollaborator();
    $admin = adminUser();

    Sanctum::actingAs($alice);
    $folderId = $this->postJson('/api/folders', ['name' => 'Notes secrètes Alice'])
        ->assertCreated()
        ->json('folder.id');
    $docId = $this->post('/api/documents', [
        'title' => 'Journal privé',
        'folder_id' => $folderId,
        'file' => UploadedFile::fake()->create('j.pdf', 5, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

    Sanctum::actingAs($admin);
    $names = collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('Notes secrètes Alice');
    $this->getJson("/api/folders/{$folderId}")->assertForbidden();
    $this->getJson("/api/documents/{$docId}")->assertForbidden();
    $this->getJson('/api/documents')->assertOk();
    expect(collect($this->getJson('/api/documents')->json('data'))->pluck('id')->all())
        ->not->toContain($docId);

    $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
    $counts = $dashboard['dashboard']['counts'] ?? $dashboard['counts'] ?? [];
    expect((int) ($counts['documents'] ?? 0))->toBe(0);

    // Partage explicite → visible + compté
    Sanctum::actingAs($alice);
    $this->postJson("/api/documents/{$docId}/accesses", [
        'user_id' => $admin->id,
        'abilities' => ['view', 'download'],
    ])->assertCreated();

    Sanctum::actingAs($admin);
    $this->getJson("/api/documents/{$docId}")->assertOk();
    $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
    $counts = $dashboard['dashboard']['counts'] ?? $dashboard['counts'] ?? [];
    expect((int) ($counts['documents'] ?? 0))->toBeGreaterThanOrEqual(1);
});

test('un collaborateur ne peut pas créer un dossier public de département', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $user = spaceCollaborator($rh->id);
    Sanctum::actingAs($user);

    $this->postJson('/api/folders', [
        'name' => 'Paie',
        'department_id' => $rh->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id']);

    $personal = $this->postJson('/api/folders', ['name' => 'Mes notes'])->assertCreated()->json('folder');
    expect($personal['department'])->toBeNull();
});

test('un admin crée un dossier public visible du département', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $com = Department::query()->create(['name' => 'Commercial', 'code' => 'COM']);
    $rhUser = spaceCollaborator($rh->id);
    $comUser = spaceCollaborator($com->id);

    Sanctum::actingAs(adminUser());
    $folder = $this->postJson('/api/folders', [
        'name' => 'Notes de service',
        'department_id' => $rh->id,
    ])->assertCreated()
        ->assertJsonPath('folder.department.id', $rh->id)
        ->json('folder');

    Sanctum::actingAs($rhUser);
    expect(collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all())
        ->toContain('Notes de service');
    $this->getJson("/api/folders/{$folder['id']}")->assertOk();

    Sanctum::actingAs($comUser);
    expect(collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('name')->all())
        ->not->toContain('Notes de service');
    $this->getJson("/api/folders/{$folder['id']}")->assertForbidden();
});

test('un responsable ne crée un dossier public que pour son département', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $com = Department::query()->create(['name' => 'Commercial', 'code' => 'COM']);

    $manager = User::factory()->create([
        'department_id' => $rh->id,
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $manager->roles()->attach(Role::query()->where('slug', 'responsable_departement')->firstOrFail());
    $rh->update(['manager_id' => $manager->id]);

    Sanctum::actingAs($manager);

    $this->postJson('/api/folders', [
        'name' => 'RH public',
        'department_id' => $rh->id,
    ])->assertCreated()
        ->assertJsonPath('folder.department.id', $rh->id);

    $this->postJson('/api/folders', [
        'name' => 'COM public',
        'department_id' => $com->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id']);
});

test('un chef de projet choisit le département du dossier public', function () {
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $chef = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
        'department_id' => null,
    ]);
    $chef->roles()->attach(Role::query()->where('slug', 'chef_projet')->firstOrFail());

    Sanctum::actingAs($chef);
    $this->postJson('/api/folders', [
        'name' => 'Notes RH',
        'department_id' => $rh->id,
    ])->assertCreated()
        ->assertJsonPath('folder.department.id', $rh->id);
});

