<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->isLivreur() && ! $user->isAdmin())) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès réservé aux livreurs.'], 403);
            }

            return redirect('/');
        }

        if ($user->isLivreur() && ! $user->driver) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Aucun profil livreur associé à ce compte.'], 403);
            }

            return redirect('/login');
        }

        return $next($request);
    }
}
