<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(Request $request)
    {
        $payload = $request->validate([
            'loginId' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'audience' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            return ApiResponse::ok($this->authService->login($payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function refresh(Request $request)
    {
        $payload = $request->validate([
            'refreshToken' => ['required', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->authService->refresh($payload['refreshToken']));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('UNAUTHORIZED', '認証が必要です。', 401);
        }

        return ApiResponse::ok($this->authService->me($user));
    }

    public function logout(Request $request)
    {
        $this->authService->logout($request->bearerToken());
        return ApiResponse::ok(['success' => true]);
    }
}
