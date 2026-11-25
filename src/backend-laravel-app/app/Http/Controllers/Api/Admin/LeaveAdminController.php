<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;

final class LeaveAdminController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequestService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->leaveRequestService->listForAdmin($request->only([
            'status',
            'employeeCode',
            'departmentName',
            'leaveTypeCode',
            'from',
            'to',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'APPROVED');
    }

    public function reject(Request $request, int $id)
    {
        return $this->decide($request, $id, 'REJECTED');
    }

    public function return(Request $request, int $id)
    {
        return $this->decide($request, $id, 'RETURNED');
    }

    public function grant(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'days' => ['required', 'numeric', 'min:0.01', 'max:99.99', 'decimal:0,2'],
            'grantedOn' => ['required', 'date'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:grantedOn'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->leaveRequestService->grantForAdmin($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function adjust(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'adjustmentType' => ['required', 'string', 'in:ADJUST_PLUS,ADJUST_MINUS'],
            'days' => ['required', 'numeric', 'min:0.01', 'max:99.99', 'decimal:0,2'],
            'effectiveOn' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->leaveRequestService->adjustForAdmin($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function hourlyBalance(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'effectiveOn' => ['required', 'date'],
            'expiresOn' => ['required', 'date', 'after:effectiveOn'],
            'remainingMinutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'totalMinutes' => ['required', 'integer', 'min:0', 'max:525600', 'gte:remainingMinutes'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $result = app(\App\Services\Leave\HourlyPaidLeaveService::class)->setBalance($payload, $request->user());
            return ApiResponse::ok($result);
        } catch (ApiException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }
    }

    public function employeeBalance(Request $request, int $employeeId)
    {
        $payload = $request->validate(['asOf' => ['nullable', 'date_format:Y-m-d']]);
        return ApiResponse::ok($this->leaveRequestService->balance($employeeId, $payload['asOf'] ?? null));
    }

    private function grantReviewPayload(Request $request, bool $confirm): array
    {
        return $request->validate([
            'lots' => ['required', 'array', 'min:1', 'max:20'],
            'lots.*.days' => ['required', 'numeric', 'min:0.01', 'max:99.99', 'decimal:0,2'],
            'lots.*.expiresOn' => ['required', 'date_format:Y-m-d'],
            'lots.*.originalGrantedOn' => ['nullable', 'date_format:Y-m-d'],
            'evidenceReference' => ['required', 'string', 'min:1', 'max:1000'],
            'fingerprint' => [$confirm ? 'required' : 'nullable', 'string', 'size:64'],
        ]);
    }

    public function previewGrantReview(Request $request, int $id)
    {
        return ApiResponse::ok(app(\App\Services\Leave\PaidLeaveGrantReviewService::class)->preview($id, $this->grantReviewPayload($request, false)));
    }

    public function confirmGrantReview(Request $request, int $id)
    {
        return ApiResponse::ok(app(\App\Services\Leave\PaidLeaveGrantReviewService::class)->confirm($id, $this->grantReviewPayload($request, true), $request->user()));
    }

    public function attendanceBaseline(Request $request)
    {
        $p = $request->validate(['employeeId' => ['required', 'integer', 'min:1'], 'targetDate' => ['required', 'date_format:Y-m-d']]);
        return ApiResponse::ok(app(\App\Services\Leave\LeaveAttendanceProjection::class)->review($p['employeeId'], $p['targetDate']));
    }

    public function confirmAttendanceBaseline(Request $request)
    {
        $p = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'], 'targetDate' => ['required', 'date_format:Y-m-d'],
            'baseline' => ['required', 'array'], 'baseline.absence_flag' => ['required', 'integer', 'in:0,1'],
            'baseline.special_leave_flag' => ['required', 'integer', 'in:0,1'], 'baseline.paid_leave_unit' => ['nullable', 'numeric', 'in:0,0.5,1'],
            'baseline.hour_paid_leave_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'baseline.child_care_leave_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'baseline.nursing_care_leave_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'evidenceReference' => ['required', 'string', 'min:1', 'max:1000'], 'fingerprint' => ['required', 'string', 'size:64'],
        ]);
        return ApiResponse::ok(app(\App\Services\Leave\LeaveAttendanceProjection::class)->confirmBaseline($p, $request->user()));
    }

    private function decide(Request $request, int $id, string $decision)
    {
        $payload = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->leaveRequestService->decide($id, $decision, $payload['comment'] ?? null, $request->user())
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
