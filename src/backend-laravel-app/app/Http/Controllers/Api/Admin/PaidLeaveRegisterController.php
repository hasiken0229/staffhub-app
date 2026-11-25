<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\Reports\PaidLeaveRegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaidLeaveRegisterController extends Controller
{
    public function show(Request $request, PaidLeaveRegisterService $register)
    {
        $filters = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $report = DB::transaction(fn () => $register->forEmployee((int) $filters['employeeId'], $filters['from'], $filters['to']));

        return ApiResponse::ok($report)->header('Cache-Control', 'private, no-store');
    }
}
