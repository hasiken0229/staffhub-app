<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class PayrollAdminController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function store(Request $request)
    {
        return ApiResponse::ok([
            'employeeId' => $request->input('employeeId'),
            'targetYearMonth' => $request->input('targetYearMonth'),
            'fileName' => $request->file('file')?->getClientOriginalName(),
        ]);
    }
}
