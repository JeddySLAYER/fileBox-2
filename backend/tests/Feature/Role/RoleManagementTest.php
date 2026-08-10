<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut lister les rôles', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/roles')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'permissions']]]);
});

test('un admin peut créer un rôle avec permissions', function () {
    Sanctum::actingAs(adminUser());

    $permissionIds = Permission::query()->whereIn('slug', ['documents.view', 'folders.view'])->pluck('id');

    $this->postJson('/api/roles', [
        'name' => 'Auditeur',
        'description' => 'Consultation documentaire',
        'permission_ids' => $permissionIds->all(),
    ])->assertCreated()
        ->assertJsonPath('role.slug', 'auditeur')
        ->assertJsonPath('role.permissions.0.slug', fn () => true);

    expect(Role::query()->where('slug', 'auditeur')->exists())->toBeTrue();
});

test('un rôle système ne peut pas être supprimé', function () {
    Sanctum::actingAs(adminUser());

    $role = Role::query()->where('slug', 'administrateur')->firstOrFail();

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertUnprocessable();
});

test('un admin peut synchroniser les permissions d un rôle', function () {
    Sanctum::actingAs(adminUser());

    $role = Role::query()->create([
        'name' => 'Temporaire',
        'slug' => 'temporaire',
    ]);

    $ids = Permission::query()->whereIn('slug', ['dashboard.view'])->pluck('id')->all();

    $this->putJson("/api/roles/{$role->id}/permissions", [
        'permission_ids' => $ids,
    ])->assertOk()
        ->assertJsonCount(1, 'role.permissions');
});

test('un admin peut modifier un rôle custom', function () {
    Sanctum::actingAs(adminUser());

    $role = Role::query()->create([
        'name' => 'Auditeur',
        'slug' => 'auditeur',
        'description' => 'Avant',
    ]);

    $this->putJson("/api/roles/{$role->id}", [
        'name' => 'Auditeur senior',
        'description' => 'Après',
    ])->assertOk()
        ->assertJsonPath('role.name', 'Auditeur senior')
        ->assertJsonPath('role.description', 'Après');
});

test('un admin peut supprimer un rôle custom sans utilisateurs', function () {
    Sanctum::actingAs(adminUser());

    $role = Role::query()->create([
        'name' => 'Temporaire',
        'slug' => 'temporaire-delete',
    ]);

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertOk();

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

test('un collaborateur ne peut pas gérer les rôles', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/roles')->assertForbidden();
});
