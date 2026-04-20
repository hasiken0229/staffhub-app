<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(Request $request)
    {
        // TODO: Laravel Sanctum または Session 認証へ接続
        return ApiResponse::ok([
            'accessToken' => 'dummy-token',
            'refreshToken' => 'dummy-refresh-token',
            'user' => [
                'id' => 1,
                'role' => 'EMPLOYEE',
                'employeeCode' => 'E0001',
                'name' => '山田 太郎',
            ],
        ]);
    }
}
