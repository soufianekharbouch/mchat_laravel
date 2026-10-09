<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        // On supporte tes méthodes existantes (si elles existent)
        $map = [
            'admin' => 'isAdmin',
            'nutritionist' => 'isPetNutritionist',
            'pet_nutritionist' => 'isPetNutritionist',
            'stuff' => 'isStuff',
        ];

        foreach ($roles as $role) {
            $role = strtolower(trim($role));

            // 1) si user.role existe (string)
            if (isset($user->role) && strtolower((string) $user->role) === $role) {
                return $next($request);
            }

            // 2) si méthode helper existe
            if (isset($map[$role]) && method_exists($user, $map[$role])) {
                if ($user->{$map[$role]}()) {
                    return $next($request);
                }
            }
        }

        abort(403);
    }
}
