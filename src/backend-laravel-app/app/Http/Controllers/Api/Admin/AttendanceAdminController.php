<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

final class AttendanceAdminController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    public function events(Request $request)
    {
        $result = $this->attendanceService->listEvents($request->only([
            'from',
            'to',
            'employeeCode',
            'receiveStatus',
            'deviceCode',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function daily(Request $request)
    {
        $result = $this->attendanceService->listDaily($request->only([
            'targetMonth',
            'employeeCode',
            'departmentName',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function dailyGrid(Request $request)
    {
        $result = $this->attendanceService->listDailyGrid($request->only([
            'targetMonth',
            'employeeCode',
            'departmentName',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::ok($this->attendanceService->detail($id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function updateDaily(Request $request, int $id)
    {
        $payload = $request->validate([
            'workTypeId' => ['nullable', 'integer'],
            'clockInTime' => ['nullable', 'string', 'max:5'],
            'clockInNextDay' => ['nullable', 'boolean'],
            'clockOutTime' => ['nullable', 'string', 'max:5'],
            'clockOutNextDay' => ['nullable', 'boolean'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.startTime' => ['nullable', 'string', 'max:5'],
            'breaks.*.startNextDay' => ['nullable', 'boolean'],
            'breaks.*.endTime' => ['nullable', 'string', 'max:5'],
            'breaks.*.endNextDay' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string'],
            'supervisorComment' => ['nullable', 'string'],
            'approvalStatus' => ['nullable', 'string', 'in:PENDING,APPROVED,RETURNED'],
            'approvalComment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->updateDaily($id, $payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function resetDailyManualEdit(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->attendanceService->resetManualEdit($id, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function histories(int $id)
    {
        try {
            return ApiResponse::ok($this->attendanceService->histories($id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function monthClose(Request $request)
    {
        $payload = $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->monthlyCloseSummary((string) $payload['targetMonth']));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function monthCloseStatus(Request $request)
    {
        try {
            $result = $this->attendanceService->monthCloseStatus($request->only([
                'targetMonth',
                'employeeCode',
                'employeeName',
                'departmentName',
                'locationName',
                'employmentType',
                'approvalStatus',
                'closeStatus',
                'page',
                'perPage',
            ]));

            return ApiResponse::ok($result['items'], $result['meta']);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function monthClosePrecheck(Request $request)
    {
        $payload = $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->monthClosePrecheck((string) $payload['targetMonth']));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function dailyEditRequests(Request $request)
    {
        try {
            return ApiResponse::ok($this->attendanceService->listDailyEditRequestsForAdmin($request->only([
                'status',
                'employeeCode',
                'departmentName',
                'from',
                'to',
            ])));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function approveDailyEditRequest(Request $request, int $id)
    {
        return $this->decideDailyEditRequest($request, $id, 'APPROVED');
    }

    public function returnDailyEditRequest(Request $request, int $id)
    {
        return $this->decideDailyEditRequest($request, $id, 'RETURNED');
    }

    public function errors(Request $request)
    {
        try {
            $result = $this->attendanceService->listErrors($request->only([
                'fromMonth',
                'toMonth',
                'errorCode',
                'handlingStatus',
                'employeeCode',
                'employeeName',
                'departmentName',
                'locationName',
                'employmentType',
                'approvalStatus',
                'page',
                'perPage',
            ]));

            return ApiResponse::ok($result['items'], $result['meta']);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function resolveError(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer'],
            'targetDate' => ['required', 'date'],
            'errorCode' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', 'in:OPEN,IN_PROGRESS,RESOLVED,IGNORED'],
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->resolveError($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function updateMonthClose(Request $request)
    {
        $payload = $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
            'status' => ['required', 'string', 'in:OPEN,CLOSED'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok(
                $this->attendanceService->updateMonthlyClose(
                    (string) $payload['targetMonth'],
                    (string) $payload['status'],
                    $payload['note'] ?? null,
                    $request->user(),
                )
            );
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function approvals(Request $request)
    {
        $result = $this->attendanceService->listApprovals($request->only([
            'status',
            'from',
            'to',
            'employeeCode',
            'departmentName',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'APPROVED');
    }

    public function bulkApprove(Request $request)
    {
        return $this->bulkDecide($request, 'APPROVED');
    }

    public function return(Request $request, int $id)
    {
        return $this->decide($request, $id, 'RETURNED');
    }

    public function bulkReturn(Request $request)
    {
        return $this->bulkDecide($request, 'RETURNED');
    }

    private function decide(Request $request, int $id, string $decision)
    {
        $payload = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->attendanceService->decideApproval($id, $decision, $payload['comment'] ?? null, $request->user())
            );
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    private function bulkDecide(Request $request, string $decision)
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->attendanceService->bulkDecideApproval(
                    $payload['ids'],
                    $decision,
                    $payload['comment'] ?? null,
                    $request->user()
                )
            );
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    private function decideDailyEditRequest(Request $request, int $id, string $decision)
    {
        $payload = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->attendanceService->decideDailyEditRequest($id, $decision, $payload['comment'] ?? null, $request->user())
            );
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }
}
