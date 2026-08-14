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

test('un collaborateur peut lister les utilisateurs pour partager', function () {
    Sanctum::actingAs(collaboratorUser());

    $this->getJson('/api/users')->assertOk()->assertJsonStructure(['data']);
    $this->postJson('/api/users', [
        'name' => 'Intrus',
        'email' => 'intrus@filebox.test',
    ])->assertForbidden();
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
        ->assertJsonStructure(['user']);

    $created = User::query()->where('email', 'alice@filebox.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->temporary_password_expires_at)->not->toBeNull()
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

test('désactiver un utilisateur révoque ses sessions', function () {
    Sanctum::actingAs(adminUser());

    $target = User::factory()->create(['is_active' => true]);
    $token = $target->createToken('pest')->plainTextToken;
    expect($target->tokens()->count())->toBe(1);

    $this->putJson("/api/users/{$target->id}", [
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('user.is_active', false);

    expect($target->fresh()->is_active)->toBeFalse()
        ->and($target->fresh()->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('un utilisateur désactivé ne peut plus utiliser l API même avec un ancien token', function () {
    $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    $token = $user->createToken('pest')->plainTextToken;

    $user->update(['is_active' => false]);

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('account_disabled', true);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('un admin ne peut pas désactiver son propre compte', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->putJson("/api/users/{$admin->id}", [
        'is_active' => false,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);

    expect($admin->fresh()->is_active)->toBeTrue();
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
        ->assertJsonPath('user.temporary_password_expires_at', fn ($value) => ! empty($value));

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
