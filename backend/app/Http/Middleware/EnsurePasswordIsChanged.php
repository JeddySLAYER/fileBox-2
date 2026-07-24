<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Bloque l'accès à l'API tant que le mot de passe temporaire n'a pas été changé.
     * Routes autorisées : me, change-password, logout.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'Vous devez changer votre mot de passe avant de continuer.',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
