<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /** Usage: ->middleware('role:staff,admin,superadmin') */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn ($r) => Role::from($r), $roles);

        if (!$user || !$user->active || !in_array($user->role, $allowed, true)) {
            abort(403, 'You do not have access to this area.');
        }
        return $next($request);
    }
}
