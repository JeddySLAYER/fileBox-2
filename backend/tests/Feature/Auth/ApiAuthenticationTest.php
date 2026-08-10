<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('un utilisateur peut se connecter via l API et recevoir un token', function () {
    $user = User::factory()->create([
        'email' => 'collab@filebox.test',
        'password' => 'password',
        'is_active' => true,
        'must_change_password' => false,
    ]);

    $role = Role::query()->where('slug', 'collaborateur')->firstOrFail();
    $user->roles()->attach($role);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'collab@filebox.test',
        'password' => 'password',
        'device_name' => 'pest',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('must_change_password', false)
        ->assertJsonPath('user.email', 'collab@filebox.test')
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']]);

    expect($response->json('token'))->not->toBeEmpty();
});

test('la connexion échoue avec un mauvais mot de passe', function () {
    User::factory()->create([
        'email' => 'collab@filebox.test',
        'password' => 'password',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'collab@filebox.test',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('login.attempts_remaining', 4)
        ->assertJsonPath('login.attempts_made', 1)
        ->assertJsonPath('login.locked', false);
});

test('après deux échecs la réponse indique les essais restants', function () {
    User::factory()->create([
        'email' => 'throttle@filebox.test',
        'password' => 'password',
    ]);

    $payload = [
        'email' => 'throttle@filebox.test',
        'password' => 'wrong-password',
        'device_name' => 'pest',
    ];

    $this->postJson('/api/auth/login', $payload)->assertUnprocessable();

    $this->postJson('/api/auth/login', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('login.attempts_made', 2)
        ->assertJsonPath('login.attempts_remaining', 3)
        ->assertJsonPath('login.locked', false);
});

test('cinq échecs verrouillent la connexion avec un délai en minutes', function () {
    User::factory()->create([
        'email' => 'locked@filebox.test',
        'password' => 'password',
    ]);

    $payload = [
        'email' => 'locked@filebox.test',
        'password' => 'wrong-password',
        'device_name' => 'pest',
    ];

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/auth/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/auth/login', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('login.locked', true)
        ->assertJsonPath('login.attempts_remaining', 0)
        ->assertJsonPath('login.retry_after_minutes', 15);

    $this->postJson('/api/auth/login', [
        'email' => 'locked@filebox.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('login.locked', true)
        ->assertJsonStructure(['login' => ['retry_after_minutes']]);
});

test('un compte désactivé ne peut pas se connecter', function () {
    User::factory()->create([
        'email' => 'disabled@filebox.test',
        'password' => 'password',
        'is_active' => false,
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'disabled@filebox.test',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('un mot de passe temporaire expiré ne permet pas la connexion', function () {
    User::factory()->create([
        'email' => 'temp-expired@filebox.test',
        'password' => 'password',
        'must_change_password' => true,
        'temporary_password_expires_at' => now()->subHour(),
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'temp-expired@filebox.test',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('un utilisateur authentifié peut consulter son profil', function () {
    $user = User::factory()->create([
        'must_change_password' => false,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

test('un utilisateur peut se déconnecter et invalider son token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('pest')->plainTextToken;

    expect($user->tokens()->count())->toBe(1);

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Déconnexion réussie.');

    expect($user->fresh()->tokens()->count())->toBe(0);

    // Le guard reste hydraté en mémoire entre deux requêtes du même test
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('un utilisateur doit changer son mot de passe temporaire', function () {
    $user = User::factory()->create([
        'password' => 'TempPass1!',
        'must_change_password' => true,
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/auth/password', [
        'current_password' => 'TempPass1!',
        'password' => 'NewSecurePass1!',
        'password_confirmation' => 'NewSecurePass1!',
    ])->assertOk()
        ->assertJsonPath('user.must_change_password', false);

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('NewSecurePass1!', $user->password))->toBeTrue();
});

test('le middleware bloque les routes protégées si le mot de passe doit être changé', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
    ]);

    Sanctum::actingAs($user);

    // me / password / logout restent accessibles
    $this->getJson('/api/auth/me')->assertOk();

    // Une route protégée par password.changed doit être refusée
    Route::middleware(['auth:sanctum', 'password.changed'])
        ->get('/api/auth/protected-probe', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/auth/protected-probe')
        ->assertForbidden()
        ->assertJsonPath('must_change_password', true);
});
