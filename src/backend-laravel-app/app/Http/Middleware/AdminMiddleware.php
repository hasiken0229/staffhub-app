<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Local bootstrapping stays easy until employee/admin auth is wired in.
        if ($user === null && app()->environment('local')) {
            return $next($request);
        }

        if ($user === null) {
            abort(401, 'Unauthorized.');
        }

        $isAdmin = false;

        foreach (['is_admin', 'isAdmin'] as $property) {
            if (isset($user->{$property}) && filter_var($user->{$property}, FILTER_VALIDATE_BOOL)) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            foreach (['role', 'user_type', 'userType'] as $property) {
                if (!isset($user->{$property})) {
                    continue;
                }

                $normalized = strtoupper((string) $user->{$property});
                if (in_array($normalized, ['ADMIN', 'SYSTEM_ADMIN', 'HR'], true)) {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
