<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('un admin peut lister les utilisateurs', function () {
    Sanctum::actingAs(adminUser());
    User::factory()->count(3)->create();

    $this->getJson('/api/users')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('un collaborateur ne peut pas lister les utilisateurs', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/users')->assertForbidden();
});

test('un admin peut créer un utilisateur avec mot de passe temporaire', function () {
    Notification::fake();

    Sanctum::actingAs(adminUser());

    $roleId = Role::query()->where('slug', 'collaborateur')->value('id');

    $response = $this->postJson('/api/users', [
        'name' => 'Alice Martin',
        'email' => 'alice@filebox.test',
        'role_ids' => [$roleId],
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'alice@filebox.test')
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonStructure(['temporary_password', 'user']);

    $created = User::query()->where('email', 'alice@filebox.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->roles->pluck('slug')->all())->toContain('collaborateur');

    Notification::assertSentTo($created, TemporaryPasswordNotification::class);
});

test('un admin peut consulter un utilisateur', function () {
    Sanctum::actingAs(adminUser());
    $target = User::factory()->create();

    $this->getJson("/api/users/{$target->id}")
        ->assertOk()
        ->assertJsonPath('user.id', $target->id);
});

test('un admin peut mettre à jour un utilisateur', function () {
    Sanctum::actingAs(adminUser());
    $target = User::factory()->create(['name' => 'Old Name']);
    $roleId = Role::query()->where('slug', 'chef_projet')->value('id');

    $this->putJson("/api/users/{$target->id}", [
        'name' => 'New Name',
        'role_ids' => [$roleId],
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('user.is_active', false);

    expect($target->fresh()->roles->pluck('slug')->all())->toContain('chef_projet');
});

test('un admin peut supprimer un utilisateur et réutiliser son email', function () {
    Sanctum::actingAs(adminUser());
    $target = User::factory()->create(['email' => 'reuse@filebox.test']);
    $email = $target->email;

    $this->deleteJson("/api/users/{$target->id}")
        ->assertOk();

    $archived = User::withTrashed()->find($target->id);

    expect($archived->trashed())->toBeTrue()
        ->and($archived->email)->not->toBe($email)
        ->and($archived->is_active)->toBeFalse();

    $this->postJson('/api/users', [
        'name' => 'Nouveau',
        'email' => $email,
    ])->assertCreated()
        ->assertJsonPath('user.email', $email);
});

test('un admin ne peut pas se supprimer lui-même', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/users/{$admin->id}")
        ->assertForbidden();
});

test('la restauration utilisateur n\'existe plus', function () {
    Sanctum::actingAs(adminUser());
    $target = User::factory()->create();
    $this->deleteJson("/api/users/{$target->id}")->assertOk();

    $this->postJson("/api/users/{$target->id}/restore")->assertNotFound();
});

test('un admin peut régénérer un mot de passe temporaire', function () {
    Notification::fake();

    Sanctum::actingAs(adminUser());
    $target = User::factory()->create([
        'must_change_password' => false,
    ]);
    $target->createToken('old');

    $this->postJson("/api/users/{$target->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonStructure(['temporary_password']);

    expect($target->fresh()->tokens()->count())->toBe(0);

    Notification::assertSentTo($target->fresh(), TemporaryPasswordNotification::class);
});

test('la gestion utilisateurs exige un mot de passe déjà changé', function () {
    $admin = adminUser();
    $admin->update(['must_change_password' => true]);
    Sanctum::actingAs($admin);

    $this->getJson('/api/users')
        ->assertForbidden()
        ->assertJsonPath('must_change_password', true);
});
