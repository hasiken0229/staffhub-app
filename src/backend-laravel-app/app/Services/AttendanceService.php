<?php

namespace App\Services;

use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\AttendanceErrorService;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\DailyEditRequestService;
use App\Services\Attendance\DailyEditService;
use App\Services\Attendance\MonthCloseService;
use App\Services\Attendance\PunchService;
use Illuminate\Auth\GenericUser;

final class AttendanceService
{
    public function __construct(
        private readonly PunchService $punchService,
        private readonly DailyAttendanceService $dailyAttendanceService,
        private readonly DailyEditService $dailyEditService,
        private readonly DailyEditRequestService $dailyEditRequestService,
        private readonly AttendanceErrorService $attendanceErrorService,
        private readonly MonthCloseService $monthCloseService,
        private readonly AttendanceApprovalService $attendanceApprovalService,
    ) {
    }

    public function punch(array $payload): array
    {
        return $this->punchService->punch($payload);
    }

    public function heartbeat(array $payload): array
    {
        return $this->punchService->heartbeat($payload);
    }

    public function listCardRegistrationEmployees(array $payload): array
    {
        return $this->punchService->listCardRegistrationEmployees($payload);
    }

    public function assignCardFromDevice(array $payload): array
    {
        return $this->punchService->assignCardFromDevice($payload);
    }

    public function listEvents(array $filters): array
    {
        return $this->dailyAttendanceService->listEvents($filters);
    }

    public function listDaily(array $filters): array
    {
        return $this->dailyAttendanceService->listDaily($filters);
    }

    public function listDailyGrid(array $filters): array
    {
        return $this->dailyAttendanceService->listDailyGrid($filters);
    }

    public function listMonthlyCalendar(array $filters): array
    {
        return $this->dailyAttendanceService->listMonthlyCalendar($filters);
    }

    public function detail(int $dailyId): array
    {
        return $this->dailyAttendanceService->detail($dailyId);
    }

    public function updateDaily(int $dailyId, array $payload, GenericUser $actor): array
    {
        return $this->dailyEditService->updateDaily($dailyId, $payload, $actor);
    }

    public function createDaily(int $employeeId, string $targetDate, GenericUser $actor): array
    {
        return $this->dailyEditService->createDaily($employeeId, $targetDate, $actor);
    }

    public function resetManualEdit(int $dailyId, GenericUser $actor): array
    {
        return $this->dailyEditService->resetManualEdit($dailyId, $actor);
    }

    public function histories(int $dailyId): array
    {
        return $this->dailyEditService->histories($dailyId);
    }

    public function createDailyEditRequest(int $employeeId, array $payload): array
    {
        return $this->dailyEditRequestService->createDailyEditRequest($employeeId, $payload);
    }

    public function listDailyEditRequestsForEmployee(int $employeeId, array $filters = []): array
    {
        return $this->dailyEditRequestService->listDailyEditRequestsForEmployee($employeeId, $filters);
    }

    public function listDailyEditRequestsForAdmin(array $filters = []): array
    {
        return $this->dailyEditRequestService->listDailyEditRequestsForAdmin($filters);
    }

    public function decideDailyEditRequest(int $requestId, string $decision, ?string $comment, GenericUser $actor): array
    {
        return $this->dailyEditRequestService->decideDailyEditRequest($requestId, $decision, $comment, $actor);
    }

    public function listErrors(array $filters): array
    {
        return $this->attendanceErrorService->listErrors($filters);
    }

    public function resolveError(array $payload, GenericUser $actor): array
    {
        return $this->attendanceErrorService->resolveError($payload, $actor);
    }

    public function monthCloseStatus(array $filters): array
    {
        return $this->monthCloseService->monthCloseStatus($filters);
    }

    public function monthlyCloseSummary(string $targetMonth): array
    {
        return $this->monthCloseService->monthlyCloseSummary($targetMonth);
    }

    public function monthClosePrecheck(string $targetMonth): array
    {
        return $this->monthCloseService->monthClosePrecheck($targetMonth);
    }

    public function updateMonthlyClose(string $targetMonth, string $status, ?string $note, GenericUser $actor): array
    {
        return $this->monthCloseService->updateMonthlyClose($targetMonth, $status, $note, $actor);
    }

    public function listApprovals(array $filters): array
    {
        return $this->attendanceApprovalService->listApprovals($filters);
    }

    public function decideApproval(int $dailyId, string $decision, ?string $comment, GenericUser $actor): array
    {
        return $this->attendanceApprovalService->decideApproval($dailyId, $decision, $comment, $actor);
    }

    public function bulkDecideApproval(array $dailyIds, string $decision, ?string $comment, GenericUser $actor): array
    {
        return $this->attendanceApprovalService->bulkDecideApproval($dailyIds, $decision, $comment, $actor);
    }
}
