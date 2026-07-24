<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return array{token: string, user: User}
     */
    public function login(string $email, string $password, string $deviceName, string $ip): array
    {
        $this->ensureIsNotRateLimited($email, $ip);

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($this->throttleKey($email, $ip));

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est désactivé.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($email, $ip));

        $user->load(['roles.permissions', 'department']);

        $token = $user->createToken($deviceName)->plainTextToken;

        $this->activityLog->log(
            action: 'auth.login',
            user: $user,
            description: "Connexion de {$user->email}",
        );

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

        $this->activityLog->log(
            action: 'auth.logout',
            user: $user,
            description: "Déconnexion de {$user->email}",
        );
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
        $user->save();

        // ponytail: revoke other sessions after password change; upgrade: selective device keep
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return $user->fresh(['roles.permissions', 'department']);
    }

    private function ensureIsNotRateLimited(string $email, string $ip): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($email, $ip), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey($email, $ip));

        throw ValidationException::withMessages([
            'email' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ])],
        ]);
    }

    private function throttleKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }
}
