<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }

        if (! $user || $user->isLivreur()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès réservé à l’administration.'], 403);
            }

            return redirect('/mes-missions');
        }

        return $next($request);
    }
}
