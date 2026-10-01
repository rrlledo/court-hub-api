<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && $user->tenant && ! $user->tenant->is_active) {
            abort(403, 'This tenant has been suspended.');
        }

        return $next($request);
    }
}
