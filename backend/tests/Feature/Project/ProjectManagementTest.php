<?php

use App\Models\Department;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut créer un projet avec membres départements et dossier racine', function () {
    Sanctum::actingAs(adminUser());

    $d1 = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $d2 = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $manager = User::factory()->create(['department_id' => $d1->id]);
    $member = User::factory()->create(['department_id' => $d2->id]);

    $response = $this->postJson('/api/projects', [
        'name' => 'Migration ERP',
        'code' => 'PRJ-ERP-2026',
        'department_ids' => [$d1->id, $d2->id],
        'manager_id' => $manager->id,
        'member_ids' => [$member->id],
        'status' => 'actif',
        'starts_at' => '2026-01-01',
        'ends_at' => '2026-12-31',
    ])->assertCreated()
        ->assertJsonPath('project.code', 'PRJ-ERP-2026')
        ->assertJsonPath('project.status', 'actif')
        ->assertJsonPath('project.starts_at', '2026-01-01')
        ->assertJsonCount(2, 'project.departments');

    $project = Project::query()->where('code', 'PRJ-ERP-2026')->first();
    $memberIds = $project->members()->pluck('users.id')->all();
    expect($project->root_folder_id)->not->toBeNull()
        ->and($memberIds)->toContain($manager->id)
        ->and($memberIds)->toContain($member->id)
        ->and(Folder::query()->find($project->root_folder_id)->is_project_root)->toBeTrue();
});

test('le dossier racine projet n apparaît pas dans la liste racine explorateur', function () {
    Sanctum::actingAs(adminUser());

    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);

    $this->postJson('/api/projects', [
        'name' => 'Secret',
        'code' => 'PRJ-SEC',
        'department_ids' => [$dept->id],
    ])->assertCreated();

    $this->postJson('/api/folders', ['name' => 'Normal'])->assertCreated();

    $folders = $this->getJson('/api/folders')->assertOk()->json('data');
    $names = collect($folders)->pluck('name')->all();

    expect($names)->toContain('Normal')
        ->and($names)->not->toContain('Secret');
});

test('un admin peut mettre à jour un projet', function () {
    Sanctum::actingAs(adminUser());

    $project = Project::query()->create([
        'name' => 'Ancien',
        'code' => 'PRJ-OLD',
        'status' => 'actif',
    ]);

    $this->putJson("/api/projects/{$project->id}", [
        'name' => 'Nouveau',
        'status' => 'en_pause',
        'starts_at' => '2026-03-01',
        'ends_at' => '2026-06-01',
    ])->assertOk()
        ->assertJsonPath('project.name', 'Nouveau')
        ->assertJsonPath('project.status', 'en_pause');
});

test('un admin peut synchroniser les membres d un projet', function () {
    Sanctum::actingAs(adminUser());

    $project = Project::query()->create([
        'name' => 'Refonte GED',
        'code' => 'PRJ-GED-2026',
    ]);
    $members = User::factory()->count(2)->create();

    $this->putJson("/api/projects/{$project->id}/members", [
        'member_ids' => $members->pluck('id')->all(),
    ])->assertOk()
        ->assertJsonCount(2, 'project.members');
});

test('un admin peut supprimer un projet et réutiliser son code', function () {
    Sanctum::actingAs(adminUser());

    $project = Project::query()->create([
        'name' => 'Archive',
        'code' => 'PRJ-ARC-2026',
    ]);

    $this->deleteJson("/api/projects/{$project->id}")->assertOk();

    $archived = Project::withTrashed()->find($project->id);
    expect($archived->trashed())->toBeTrue()
        ->and($archived->code)->not->toBe('PRJ-ARC-2026');

    $this->postJson('/api/projects', [
        'name' => 'Archive 2',
        'code' => 'PRJ-ARC-2026',
        'department_ids' => [Department::query()->create(['name' => 'Ops', 'code' => 'OPS'])->id],
    ])->assertCreated()
        ->assertJsonPath('project.code', 'PRJ-ARC-2026');

    $this->postJson("/api/projects/{$project->id}/restore")->assertNotFound();
});

test('un admin peut filtrer les projets par département', function () {
    Sanctum::actingAs(adminUser());

    $dsi = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);

    $pA = Project::query()->create(['name' => 'A', 'code' => 'PRJ-A', 'department_id' => $dsi->id]);
    $pA->departments()->attach($dsi->id);
    $pB = Project::query()->create(['name' => 'B', 'code' => 'PRJ-B', 'department_id' => $rh->id]);
    $pB->departments()->attach($rh->id);

    $response = $this->getJson("/api/projects?department_id={$dsi->id}")->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['PRJ-A']);
});

test('un collaborateur ne voit que les projets dont il est membre', function () {
    $collab = collaboratorUser();
    Sanctum::actingAs($collab);

    $mine = Project::query()->create(['name' => 'Mine', 'code' => 'PRJ-MINE']);
    $mine->members()->attach($collab->id);
    Project::query()->create(['name' => 'Other', 'code' => 'PRJ-OTHER']);

    $codes = collect($this->getJson('/api/projects')->assertOk()->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['PRJ-MINE']);
});

test('un collaborateur ne peut pas créer de projet', function () {
    Sanctum::actingAs(collaboratorUser());

    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);

    $this->postJson('/api/projects', [
        'name' => 'Interdit',
        'department_ids' => [$dept->id],
    ])->assertForbidden();
});

test('un responsable crée un projet lié automatiquement à son département', function () {
    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $other = Department::query()->create(['name' => 'RH', 'code' => 'RH']);

    $manager = User::factory()->create([
        'department_id' => $dept->id,
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $manager->roles()->attach(Role::query()->where('slug', 'responsable_departement')->firstOrFail());
    $dept->update(['manager_id' => $manager->id]);

    Sanctum::actingAs($manager);

    $this->postJson('/api/projects', [
        'name' => 'Projet DSI',
        'code' => 'PRJ-DSI-AUTO',
        'department_ids' => [$other->id],
    ])->assertCreated()
        ->assertJsonPath('project.code', 'PRJ-DSI-AUTO')
        ->assertJsonPath('project.departments.0.id', $dept->id);

    $project = Project::query()->where('code', 'PRJ-DSI-AUTO')->first();
    expect($project->department_id)->toBe($dept->id)
        ->and($project->departments()->pluck('departments.id')->all())->toBe([$dept->id])
        ->and($project->members()->where('users.id', $manager->id)->exists())->toBeTrue();
});

test('un responsable ne voit que les projets dont il est membre', function () {
    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $manager = User::factory()->create([
        'department_id' => $dept->id,
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $manager->roles()->attach(Role::query()->where('slug', 'responsable_departement')->firstOrFail());
    $dept->update(['manager_id' => $manager->id]);

    $mine = Project::query()->create(['name' => 'Mine', 'code' => 'PRJ-RESP-MINE', 'department_id' => $dept->id]);
    $mine->departments()->attach($dept->id);
    $mine->members()->attach($manager->id);

    $other = Project::query()->create(['name' => 'Other', 'code' => 'PRJ-RESP-OTHER', 'department_id' => $dept->id]);
    $other->departments()->attach($dept->id);

    Sanctum::actingAs($manager);

    $codes = collect($this->getJson('/api/projects')->assertOk()->json('data'))->pluck('code')->all();
    expect($codes)->toBe(['PRJ-RESP-MINE']);
});

test('associer un département ajoute automatiquement son responsable', function () {
    Sanctum::actingAs(adminUser());

    $dept = Department::query()->create(['name' => 'RH', 'code' => 'RH']);
    $manager = User::factory()->create([
        'department_id' => $dept->id,
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $manager->roles()->attach(Role::query()->where('slug', 'responsable_departement')->firstOrFail());
    $dept->update(['manager_id' => $manager->id]);

    $response = $this->postJson('/api/projects', [
        'name' => 'Cross',
        'code' => 'PRJ-CROSS',
        'department_ids' => [$dept->id],
    ])->assertCreated();

    $memberIds = collect($response->json('project.members'))->pluck('id')->all();
    expect($memberIds)->toContain($manager->id);
});

test('nommer un responsable l ajoute aux projets existants du département', function () {
    Sanctum::actingAs(adminUser());

    $dept = Department::query()->create(['name' => 'Legal', 'code' => 'LEG']);
    $project = Project::query()->create([
        'name' => 'Exist',
        'code' => 'PRJ-EXIST-LEG',
        'department_id' => $dept->id,
    ]);
    $project->departments()->attach($dept->id);

    $manager = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);

    $this->putJson("/api/departments/{$dept->id}", [
        'manager_id' => $manager->id,
    ])->assertOk();

    expect($manager->fresh()->department_id)->toBe($dept->id)
        ->and($project->members()->where('users.id', $manager->id)->exists())->toBeTrue();
});

test('un utilisateur ne peut être responsable que d un seul département', function () {
    Sanctum::actingAs(adminUser());

    $manager = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
    $d1 = Department::query()->create(['name' => 'A', 'code' => 'DA', 'manager_id' => $manager->id]);
    $manager->update(['department_id' => $d1->id]);
    $d2 = Department::query()->create(['name' => 'B', 'code' => 'DB']);

    $this->putJson("/api/departments/{$d2->id}", [
        'manager_id' => $manager->id,
    ])->assertOk();

    expect($d1->fresh()->manager_id)->toBeNull()
        ->and($d2->fresh()->manager_id)->toBe($manager->id)
        ->and($manager->fresh()->department_id)->toBe($d2->id);
});

test('le createur admin est toujours membre du projet', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $dept = Department::query()->create(['name' => 'DSI', 'code' => 'DSI2']);

    $response = $this->postJson('/api/projects', [
        'name' => 'Créé par admin',
        'code' => 'PRJ-CREATOR',
        'department_ids' => [$dept->id],
    ])->assertCreated();

    $memberIds = collect($response->json('project.members'))->pluck('id')->all();
    expect($memberIds)->toContain($admin->id)
        ->and($response->json('project.created_by'))->toBe($admin->id);
});

test('le role invite ne peut pas etre combine', function () {
    Sanctum::actingAs(adminUser());

    $inviteId = Role::query()->where('slug', 'invite')->value('id');
    $collabId = Role::query()->where('slug', 'collaborateur')->value('id');

    $user = User::factory()->create(['must_change_password' => false, 'is_active' => true]);

    $this->putJson("/api/users/{$user->id}", [
        'role_ids' => [$inviteId, $collabId],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role_ids']);

    $this->putJson("/api/users/{$user->id}", [
        'role_ids' => [$inviteId],
    ])->assertOk()
        ->assertJsonPath('user.roles.0.slug', 'invite');
});

test('un invite ne peut pas avoir de departement', function () {
    Sanctum::actingAs(adminUser());

    $inviteId = Role::query()->where('slug', 'invite')->value('id');
    $dept = Department::query()->create(['name' => 'Ext', 'code' => 'EXT']);
    $user = User::factory()->create([
        'department_id' => $dept->id,
        'must_change_password' => false,
        'is_active' => true,
    ]);

    $this->putJson("/api/users/{$user->id}", [
        'role_ids' => [$inviteId],
        'department_id' => $dept->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['department_id']);

    $this->putJson("/api/users/{$user->id}", [
        'role_ids' => [$inviteId],
        'department_id' => null,
    ])->assertOk()
        ->assertJsonPath('user.department_id', null);
});

test('attribuer le role responsable demande confirmation si un autre existe', function () {
    Sanctum::actingAs(adminUser());

    $roleId = Role::query()->where('slug', 'responsable_departement')->value('id');
    $collabId = Role::query()->where('slug', 'collaborateur')->value('id');

    $dept = Department::query()->create(['name' => 'Fin', 'code' => 'FIN']);
    $old = User::factory()->create(['department_id' => $dept->id, 'must_change_password' => false, 'is_active' => true]);
    $old->roles()->attach($roleId);
    $dept->update(['manager_id' => $old->id]);

    $newbie = User::factory()->create(['department_id' => $dept->id, 'must_change_password' => false, 'is_active' => true]);
    $newbie->roles()->attach($collabId);

    $this->putJson("/api/users/{$newbie->id}", [
        'role_ids' => [$roleId],
        'department_id' => $dept->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['replace_department_manager']);

    $this->putJson("/api/users/{$newbie->id}", [
        'role_ids' => [$roleId],
        'department_id' => $dept->id,
        'replace_department_manager' => true,
    ])->assertOk();

    expect($dept->fresh()->manager_id)->toBe($newbie->id)
        ->and($newbie->fresh()->roles->pluck('slug')->all())->toContain('responsable_departement')
        ->and($old->fresh()->roles->pluck('slug')->all())->not->toContain('responsable_departement')
        ->and($old->fresh()->roles->pluck('slug')->all())->toContain('collaborateur');
});
