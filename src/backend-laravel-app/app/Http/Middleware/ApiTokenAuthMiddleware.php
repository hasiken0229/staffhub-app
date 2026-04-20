<?php

namespace App\Http\Middleware;

use App\Services\ApiResponse;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ApiTokenAuthMiddleware
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();
        $user = $this->authService->resolveAccessUser($plainToken);

        if ($user === null) {
            return ApiResponse::error('UNAUTHORIZED', '認証が必要です。', 401);
        }

        $request->setUserResolver(static fn () => $user);
        Auth::guard()->setUser($user);

        return $next($request);
    }
}
