<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut créer un département', function () {
    Sanctum::actingAs(adminUser());
    $manager = User::factory()->create();

    $this->postJson('/api/departments', [
        'name' => 'Direction Informatique',
        'code' => 'DSI',
        'manager_id' => $manager->id,
    ])->assertCreated()
        ->assertJsonPath('department.code', 'DSI')
        ->assertJsonPath('department.manager.id', $manager->id);
});

test('un admin peut lister et consulter un département', function () {
    Sanctum::actingAs(adminUser());
    $department = Department::query()->create([
        'name' => 'RH',
        'code' => 'RH',
    ]);

    $this->getJson('/api/departments')->assertOk();
    $this->getJson("/api/departments/{$department->id}")
        ->assertOk()
        ->assertJsonPath('department.code', 'RH');
});

test('un admin peut mettre à jour un département', function () {
    Sanctum::actingAs(adminUser());
    $department = Department::query()->create(['name' => 'Finance', 'code' => 'FIN']);

    $this->putJson("/api/departments/{$department->id}", [
        'name' => 'Finance & Comptabilité',
    ])->assertOk()
        ->assertJsonPath('department.name', 'Finance & Comptabilité');
});

test('un admin peut supprimer puis restaurer un département', function () {
    Sanctum::actingAs(adminUser());
    $department = Department::query()->create(['name' => 'Logistique', 'code' => 'LOG']);

    $this->deleteJson("/api/departments/{$department->id}")->assertOk();
    expect($department->fresh()->trashed())->toBeTrue();

    $this->postJson("/api/departments/{$department->id}/restore")->assertOk();
    expect($department->fresh()->trashed())->toBeFalse();
});

test('un collaborateur ne peut pas gérer les départements', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/departments')->assertForbidden();
});
