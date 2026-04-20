<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class PayrollController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function show(int $id)
    {
        return ApiResponse::ok([
            'id' => $id,
            'downloadUrl' => null,
            'expiresAt' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function markViewed(Request $request, int $id)
    {
        $request->validate([
            'viewedAt' => ['required', 'date'],
        ]);

        return ApiResponse::ok([
            'success' => true,
            'statementId' => $id,
        ]);
    }
}
