<?php

use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut créer un projet avec membres', function () {
    Sanctum::actingAs(adminUser());

    $department = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $manager = User::factory()->create();
    $member = User::factory()->create();

    $this->postJson('/api/projects', [
        'name' => 'Migration ERP',
        'code' => 'PRJ-ERP-2026',
        'department_id' => $department->id,
        'manager_id' => $manager->id,
        'member_ids' => [$member->id],
    ])->assertCreated()
        ->assertJsonPath('project.code', 'PRJ-ERP-2026')
        ->assertJsonPath('project.department.code', 'DSI')
        ->assertJsonCount(1, 'project.members');
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

test('un admin peut supprimer puis restaurer un projet', function () {
    Sanctum::actingAs(adminUser());

    $project = Project::query()->create([
        'name' => 'Archive',
        'code' => 'PRJ-ARC-2026',
    ]);

    $this->deleteJson("/api/projects/{$project->id}")->assertOk();
    expect($project->fresh()->trashed())->toBeTrue();

    $this->postJson("/api/projects/{$project->id}/restore")->assertOk();
    expect($project->fresh()->trashed())->toBeFalse();
});

test('un admin peut filtrer les projets par département', function () {
    Sanctum::actingAs(adminUser());

    $dsi = Department::query()->create(['name' => 'DSI', 'code' => 'DSI']);
    $rh = Department::query()->create(['name' => 'RH', 'code' => 'RH']);

    Project::query()->create(['name' => 'A', 'code' => 'PRJ-A', 'department_id' => $dsi->id]);
    Project::query()->create(['name' => 'B', 'code' => 'PRJ-B', 'department_id' => $rh->id]);

    $response = $this->getJson("/api/projects?department_id={$dsi->id}")->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['PRJ-A']);
});

test('un collaborateur ne peut pas gérer les projets', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/projects')->assertForbidden();
});
