<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

final class LeaveAdminController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function approve(Request $request, int $id)
    {
        return ApiResponse::ok(['id' => $id, 'status' => 'APPROVED', 'comment' => $request->input('comment')]);
    }

    public function reject(Request $request, int $id)
    {
        return ApiResponse::ok(['id' => $id, 'status' => 'REJECTED', 'comment' => $request->input('comment')]);
    }

    public function return(Request $request, int $id)
    {
        return ApiResponse::ok(['id' => $id, 'status' => 'RETURNED', 'comment' => $request->input('comment')]);
    }
}
