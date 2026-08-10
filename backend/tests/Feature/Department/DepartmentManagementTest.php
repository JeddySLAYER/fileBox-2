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

test('changer le responsable garde l ancien comme membre du département', function () {
    Sanctum::actingAs(adminUser());

    $oldManager = User::factory()->create();
    $newManager = User::factory()->create(['department_id' => null]);

    $department = Department::query()->create([
        'name' => 'Marketing',
        'code' => 'MKT',
        'manager_id' => $oldManager->id,
    ]);
    $oldManager->update(['department_id' => $department->id]);

    $this->putJson("/api/departments/{$department->id}", [
        'manager_id' => $newManager->id,
    ])->assertOk()
        ->assertJsonPath('department.manager.id', $newManager->id);

    expect($department->fresh()->manager_id)->toBe($newManager->id)
        ->and($oldManager->fresh()->department_id)->toBe($department->id)
        ->and($newManager->fresh()->department_id)->toBe($department->id);
});

test('changer le responsable transfère le rôle responsable_departement', function () {
    Sanctum::actingAs(adminUser());

    $roleId = \App\Models\Role::query()->where('slug', 'responsable_departement')->value('id');

    $oldManager = User::factory()->create();
    $oldManager->roles()->attach($roleId);

    $newManager = User::factory()->create();
    $collaborateurId = \App\Models\Role::query()->where('slug', 'collaborateur')->value('id');
    $newManager->roles()->attach($collaborateurId);

    $department = Department::query()->create([
        'name' => 'Ventes',
        'code' => 'VTE',
        'manager_id' => $oldManager->id,
    ]);
    $oldManager->update(['department_id' => $department->id]);

    $this->putJson("/api/departments/{$department->id}", [
        'manager_id' => $newManager->id,
    ])->assertOk();

    expect($newManager->fresh()->roles->pluck('slug')->all())
        ->toContain('responsable_departement')
        ->and($oldManager->fresh()->roles->pluck('slug')->all())
        ->not->toContain('responsable_departement');
});

test('créer un département avec responsable attribue le rôle', function () {
    Sanctum::actingAs(adminUser());

    $manager = User::factory()->create();

    $this->postJson('/api/departments', [
        'name' => 'Qualité',
        'code' => 'QUA',
        'manager_id' => $manager->id,
    ])->assertCreated();

    expect($manager->fresh()->roles->pluck('slug')->all())
        ->toContain('responsable_departement')
        ->and($manager->fresh()->department_id)->not->toBeNull();
});

test('un admin peut supprimer un département et réutiliser son code', function () {
    Sanctum::actingAs(adminUser());
    $department = Department::query()->create(['name' => 'Logistique', 'code' => 'LOG']);

    $this->deleteJson("/api/departments/{$department->id}")->assertOk();

    $archived = Department::withTrashed()->find($department->id);
    expect($archived->trashed())->toBeTrue()
        ->and($archived->code)->not->toBe('LOG');

    $this->postJson('/api/departments', [
        'name' => 'Logistique 2',
        'code' => 'LOG',
    ])->assertCreated()
        ->assertJsonPath('department.code', 'LOG');

    $this->postJson("/api/departments/{$department->id}/restore")->assertNotFound();
});

test('un collaborateur ne peut pas gérer les départements', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/departments')->assertForbidden();
});
