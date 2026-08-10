<?php

use App\Models\Folder;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un utilisateur peut créer un dossier racine et un sous-dossier', function () {
    Sanctum::actingAs(adminUser());

    $root = $this->postJson('/api/folders', [
        'name' => 'Contrats',
    ])->assertCreated()
        ->json('folder');

    $this->postJson('/api/folders', [
        'name' => '2026',
        'parent_id' => $root['id'],
    ])->assertCreated()
        ->assertJsonPath('folder.parent_id', $root['id']);
});

test('la liste des dossiers retourne les racines par défaut', function () {
    Sanctum::actingAs(adminUser());

    $root = Folder::query()->create([
        'name' => 'Racine',
        'created_by' => adminUser()->id,
    ]);
    Folder::query()->create([
        'name' => 'Enfant',
        'parent_id' => $root->id,
        'created_by' => $root->created_by,
    ]);

    $response = $this->getJson('/api/folders')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toContain('Racine')
        ->and(collect($response->json('data'))->pluck('name')->all())->not->toContain('Enfant');
});

test('un dossier ne peut pas être déplacé sous lui-même ou un descendant', function () {
    Sanctum::actingAs(adminUser());
    $actorId = adminUser()->id;

    $parent = Folder::query()->create(['name' => 'Parent', 'created_by' => $actorId]);
    $child = Folder::query()->create([
        'name' => 'Child',
        'parent_id' => $parent->id,
        'created_by' => $actorId,
    ]);

    $this->putJson("/api/folders/{$parent->id}/move", [
        'parent_id' => $child->id,
    ])->assertUnprocessable();
});

test('un dossier non vide peut être soft-supprimé avec son contenu', function () {
    Sanctum::actingAs(adminUser());
    $actorId = adminUser()->id;

    $parent = Folder::query()->create(['name' => 'Parent', 'created_by' => $actorId]);
    $child = Folder::query()->create([
        'name' => 'Child',
        'parent_id' => $parent->id,
        'created_by' => $actorId,
    ]);

    $this->deleteJson("/api/folders/{$parent->id}")->assertOk();

    expect($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue();
});

test('un dossier vide peut être soft-supprimé puis restauré', function () {
    Sanctum::actingAs(adminUser());

    $folder = Folder::query()->create([
        'name' => 'Vide',
        'created_by' => adminUser()->id,
    ]);

    $this->deleteJson("/api/folders/{$folder->id}")->assertOk();
    expect($folder->fresh()->trashed())->toBeTrue();

    $this->postJson("/api/folders/{$folder->id}/restore")->assertOk();
    expect($folder->fresh()->trashed())->toBeFalse();
});
