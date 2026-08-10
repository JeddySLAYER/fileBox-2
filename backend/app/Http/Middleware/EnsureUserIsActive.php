<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Coupe l'accès API si le compte a été désactivé (même avec un token encore valide).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Ce compte est désactivé.',
                'account_disabled' => true,
            ], 401);
        }

        return $next($request);
    }
}
