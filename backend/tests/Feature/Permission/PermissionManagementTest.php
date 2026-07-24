<?php

use App\Models\Permission;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut lister les permissions', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'module']]]);
});

test('un admin peut filtrer les permissions par module', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/permissions?module=users')
        ->assertOk();

    $slugs = collect($this->getJson('/api/permissions?module=users')->json('data'))->pluck('module')->unique();

    expect($slugs->all())->toBe(['users']);
});

test('un admin peut créer une permission', function () {
    Sanctum::actingAs(adminUser());

    $this->postJson('/api/permissions', [
        'name' => 'Exporter documents',
        'slug' => 'documents.export',
        'module' => 'documents',
    ])->assertCreated()
        ->assertJsonPath('permission.slug', 'documents.export');
});

test('une permission liée à un rôle ne peut pas être supprimée', function () {
    Sanctum::actingAs(adminUser());

    $permission = Permission::query()->where('slug', 'users.view')->firstOrFail();

    $this->deleteJson("/api/permissions/{$permission->id}")
        ->assertUnprocessable();
});

test('un collaborateur ne peut pas gérer les permissions', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/permissions')->assertForbidden();
});
