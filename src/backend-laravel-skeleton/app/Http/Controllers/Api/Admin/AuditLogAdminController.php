<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;

final class AuditLogAdminController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }
}
