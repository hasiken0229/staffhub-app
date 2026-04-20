<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class LeaveController extends Controller
{
    public function balance()
    {
        return ApiResponse::ok([
            'employeeId' => 1,
            'currentBalance' => 8.5,
            'grants' => [],
        ]);
    }

    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'leaveTypeCode' => ['required', 'string', 'max:20'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'dayUnit' => ['required', 'string', 'max:10'],
            'halfDayType' => ['nullable', 'string', 'max:10'],
            'reason' => ['nullable', 'string'],
        ]);

        return ApiResponse::ok($payload);
    }

    public function show(int $id)
    {
        return ApiResponse::ok(['id' => $id]);
    }
}
