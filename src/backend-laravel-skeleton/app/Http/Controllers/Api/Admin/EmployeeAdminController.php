<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class EmployeeAdminController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function store(Request $request)
    {
        return ApiResponse::ok($request->all());
    }

    public function update(Request $request, int $id)
    {
        return ApiResponse::ok(array_merge(['id' => $id], $request->all()));
    }
}
