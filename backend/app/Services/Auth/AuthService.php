<?php

namespace App\Services\Auth;

use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserLoggedOut;
use App\Events\Auth\UserLoginFailed;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 900;

    /**
     * @return array{token: string, user: User}
     */
    public function login(string $email, string $password, string $deviceName, string $ip): array
    {
        $this->ensureIsNotRateLimited($email, $ip);

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $key = $this->throttleKey($email, $ip);
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);
            event(new UserLoginFailed($email, 'invalid_credentials', $ip));

            $attempts = RateLimiter::attempts($key);
            $remaining = max(0, self::LOGIN_MAX_ATTEMPTS - $attempts);

            if ($remaining === 0) {
                event(new Lockout(request()));
                $this->throwLoginFailure(
                    message: 'Trop de tentatives de connexion.',
                    emailError: 'Compte temporairement verrouillé.',
                    login: $this->lockoutMeta($key),
                );
            }

            $this->throwLoginFailure(
                message: 'Identifiants incorrects.',
                emailError: 'Identifiants incorrects.',
                login: [
                    'attempts_remaining' => $remaining,
                    'attempts_made' => $attempts,
                    'max_attempts' => self::LOGIN_MAX_ATTEMPTS,
                    'locked' => false,
                    'retry_after_minutes' => null,
                ],
            );
        }

        if (! $user->is_active) {
            event(new UserLoginFailed($email, 'account_disabled', $ip));

            $this->throwLoginFailure(
                message: 'Compte désactivé.',
                emailError: 'Ce compte est désactivé.',
                login: $this->neutralLoginMeta(),
            );
        }

        if ($user->must_change_password
            && $user->temporary_password_expires_at
            && $user->temporary_password_expires_at->isPast()) {
            event(new UserLoginFailed($email, 'temporary_password_expired', $ip));

            $this->throwLoginFailure(
                message: 'Mot de passe temporaire expiré.',
                emailError: 'Le mot de passe temporaire a expiré. Demandez une réinitialisation à un administrateur.',
                login: $this->neutralLoginMeta(),
            );
        }

        RateLimiter::clear($this->throttleKey($email, $ip));

        $user->load(['roles.permissions', 'department']);

        $token = $user->createToken($deviceName)->plainTextToken;

        event(new UserLoggedIn($user));

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        } else {
            $user->tokens()->delete();
        }

        event(new UserLoggedOut($user));
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->password = $newPassword;
        $user->must_change_password = false;
        $user->temporary_password_expires_at = null;
        $user->save();

        // ponytail: revoke other sessions after password change; upgrade: selective device keep
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return $user->fresh(['roles.permissions', 'department']);
    }

    private function ensureIsNotRateLimited(string $email, string $ip): void
    {
        $key = $this->throttleKey($email, $ip);

        if (! RateLimiter::tooManyAttempts($key, self::LOGIN_MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));
        event(new UserLoginFailed($email, 'rate_limited', $ip));

        $this->throwLoginFailure(
            message: 'Trop de tentatives de connexion.',
            emailError: 'Compte temporairement verrouillé.',
            login: $this->lockoutMeta($key),
        );
    }

    /**
     * @param  array<string, mixed>  $login
     */
    private function throwLoginFailure(string $message, string $emailError, array $login): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [
                'email' => [$emailError],
            ],
            'login' => $login,
        ], 422));
    }

    /** @return array<string, mixed> */
    private function lockoutMeta(string $key): array
    {
        $seconds = RateLimiter::availableIn($key);

        return [
            'attempts_remaining' => 0,
            'attempts_made' => self::LOGIN_MAX_ATTEMPTS,
            'max_attempts' => self::LOGIN_MAX_ATTEMPTS,
            'locked' => true,
            'retry_after_minutes' => max(1, (int) ceil($seconds / 60)),
            'retry_after_seconds' => $seconds,
        ];
    }

    /** @return array<string, mixed> */
    private function neutralLoginMeta(): array
    {
        return [
            'attempts_remaining' => null,
            'attempts_made' => null,
            'max_attempts' => self::LOGIN_MAX_ATTEMPTS,
            'locked' => false,
            'retry_after_minutes' => null,
        ];
    }

    private function throttleKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }
}
