<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\Leave\CareLeaveBalanceService;
use Illuminate\Http\Request;

final class CareLeaveAdminController extends Controller
{
    public function index(Request $request, CareLeaveBalanceService $service)
    {
        $p = $request->validate(['fiscalYear' => ['required', 'integer', 'between:2020,2100'], 'asOf' => ['required', 'date_format:Y-m-d']]);
        if ($p['asOf'] < $p['fiscalYear'].'-04-01' || $p['asOf'] > ($p['fiscalYear'] + 1).'-03-31') {
            throw \Illuminate\Validation\ValidationException::withMessages(['asOf' => '年度内の日付を指定してください。']);
        }
        return ApiResponse::ok($service->list($p['fiscalYear'], $p['asOf']));
    }
    public function store(Request $request, CareLeaveBalanceService $service)
    {
        $p = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'category' => ['required', 'in:CHILD,NURSING'],
            'fiscalYear' => ['required', 'integer', 'between:2020,2100'], 'eligibleCount' => ['required', 'integer', 'between:0,20'],
            'dayMinutes' => ['required', 'integer', 'between:1,1440'], 'effectiveOn' => ['required', 'date_format:Y-m-d'],
            'openingMinutes' => ['required', 'integer', 'min:0'], 'note' => ['required', 'string', 'max:500']]);
        $service->save($p, $request->user());
        return ApiResponse::ok(['saved' => true]);
    }
}
