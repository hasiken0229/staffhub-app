<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\AuditLogService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
final class AttendanceErrorService extends AttendanceServiceSupport
{
    public function listErrors(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['perPage'] ?? 50)));
        $fromMonth = $this->validateTargetMonth((string) ($filters['fromMonth'] ?? now()->format('Y-m')));
        $toMonth = $this->validateTargetMonth((string) ($filters['toMonth'] ?? $fromMonth));
        if ($toMonth < $fromMonth) {
            [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
        }

        $fromDate = CarbonImmutable::parse($fromMonth . '-01')->startOfMonth()->toDateString();
        $toDate = CarbonImmutable::parse($toMonth . '-01')->endOfMonth()->toDateString();

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
                'ad.target_date',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.absence_flag',
                'ad.special_leave_flag',
                'ad.paid_leave_unit',
                'ad.approval_status',
            ])
            ->whereBetween('ad.target_date', [$fromDate, $toDate]);

        foreach ([
            'employeeCode' => 'e.employee_code',
            'employeeName' => 'e.name',
            'departmentName' => 'e.department_name',
            'locationName' => 'e.location_name',
            'employmentType' => 'e.employment_type',
        ] as $filterKey => $column) {
            if (!empty($filters[$filterKey])) {
                $query->where($column, 'like', '%' . $filters[$filterKey] . '%');
            }
        }

        if (!empty($filters['approvalStatus'])) {
            $query->where('ad.approval_status', strtoupper((string) $filters['approvalStatus']));
        }

        $rows = $query
            ->orderByDesc('ad.target_date')
            ->orderBy('e.employee_code')
            ->get();
        $resolutionRows = DB::table('attendance_error_resolutions')
            ->whereBetween('target_date', [$fromDate, $toDate])
            ->get(['id', 'employee_id', 'target_date', 'error_code', 'status', 'comment', 'handled_by', 'handled_at']);
        $resolutionMap = [];
        foreach ($resolutionRows as $resolution) {
            $resolutionMap[$this->resolutionKey((int) $resolution->employee_id, (string) $resolution->target_date, (string) $resolution->error_code)] = $resolution;
        }
        $historyRows = DB::table('attendance_error_resolution_histories as aerh')
            ->leftJoin('employees as handled_by_emp', 'handled_by_emp.id', '=', 'aerh.handled_by')
            ->whereBetween('aerh.target_date', [$fromDate, $toDate])
            ->orderBy('aerh.handled_at')
            ->get([
                'aerh.employee_id',
                'aerh.target_date',
                'aerh.error_code',
                'aerh.old_status',
                'aerh.new_status',
                'aerh.comment',
                'aerh.handled_at',
                'handled_by_emp.employee_code as handled_by_code',
                'handled_by_emp.name as handled_by_name',
            ]);
        $historyMap = [];
        foreach ($historyRows as $history) {
            $key = $this->resolutionKey((int) $history->employee_id, (string) $history->target_date, (string) $history->error_code);
            $historyMap[$key] ??= [];
            $historyMap[$key][] = [
                'oldStatus' => $history->old_status,
                'newStatus' => $history->new_status,
                'comment' => $history->comment,
                'handledAt' => CarbonImmutable::parse((string) $history->handled_at)->toIso8601String(),
                'handledByCode' => $history->handled_by_code,
                'handledByName' => $history->handled_by_name,
            ];
        }

        $errorCodeFilter = !empty($filters['errorCode']) ? strtoupper((string) $filters['errorCode']) : null;
        $handlingStatusFilter = !empty($filters['handlingStatus']) ? strtoupper((string) $filters['handlingStatus']) : null;
        $items = [];
        foreach ($rows as $row) {
            foreach ($this->detectDailyErrors($row) as $error) {
                if ($errorCodeFilter !== null && $error['errorCode'] !== $errorCodeFilter) {
                    continue;
                }

                $resolution = $resolutionMap[$this->resolutionKey((int) $row->employee_id, (string) $row->target_date, $error['errorCode'])] ?? null;
                $historyKey = $this->resolutionKey((int) $row->employee_id, (string) $row->target_date, $error['errorCode']);
                $handlingStatus = $resolution?->status ?? 'OPEN';
                if ($handlingStatusFilter !== null && $handlingStatus !== $handlingStatusFilter) {
                    continue;
                }

                $items[] = [
                    'dailyId' => (int) $row->id,
                    'employeeId' => (int) $row->employee_id,
                    'targetDate' => $row->target_date,
                    'errorCode' => $error['errorCode'],
                    'errorName' => $error['errorName'],
                    'employeeCode' => $row->employee_code,
                    'employeeName' => $row->employee_name,
                    'departmentName' => $row->department_name,
                    'locationName' => $row->location_name,
                    'employmentType' => $row->employment_type,
                    'approvalStatus' => $row->approval_status,
                    'handlingStatus' => $handlingStatus,
                    'comment' => $resolution?->comment,
                    'handledAt' => $resolution?->handled_at ? CarbonImmutable::parse((string) $resolution->handled_at)->toIso8601String() : null,
                    'histories' => $historyMap[$historyKey] ?? [],
                ];
            }
        }

        $total = count($items);
        $pagedItems = array_slice($items, ($page - 1) * $perPage, $perPage);

        return [
            'items' => $pagedItems,
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function resolveError(array $payload, GenericUser $actor): array
    {
        $employeeId = (int) $payload['employeeId'];
        $targetDate = CarbonImmutable::parse((string) $payload['targetDate'])->toDateString();
        $errorCode = strtoupper((string) $payload['errorCode']);
        $status = strtoupper((string) $payload['status']);
        if (!in_array($status, ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'IGNORED'], true)) {
            throw new ApiException('VALIDATION_ERROR', '対応状況が不正です。', 422);
        }

        $operatorId = $this->resolveOperatorEmployeeId($actor, $employeeId);
        $comment = $this->nullableTrim($payload['comment'] ?? null);
        $existing = DB::table('attendance_error_resolutions')
            ->where('employee_id', $employeeId)
            ->where('target_date', $targetDate)
            ->where('error_code', $errorCode)
            ->first();
        $now = now();

        if ($existing === null) {
            $resolutionId = (int) DB::table('attendance_error_resolutions')->insertGetId([
                'employee_id' => $employeeId,
                'target_date' => $targetDate,
                'error_code' => $errorCode,
                'status' => $status,
                'comment' => $comment,
                'handled_by' => $operatorId > 0 ? $operatorId : null,
                'handled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $oldStatus = null;
        } else {
            $resolutionId = (int) $existing->id;
            $oldStatus = (string) $existing->status;
            DB::table('attendance_error_resolutions')
                ->where('id', $resolutionId)
                ->update([
                    'status' => $status,
                    'comment' => $comment,
                    'handled_by' => $operatorId > 0 ? $operatorId : null,
                    'handled_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $this->recordAttendanceErrorResolutionHistory(
            $resolutionId,
            $employeeId,
            $targetDate,
            $errorCode,
            $oldStatus,
            $status,
            $comment,
            $operatorId
        );

        return [
            'employeeId' => $employeeId,
            'targetDate' => $targetDate,
            'errorCode' => $errorCode,
            'status' => $status,
        ];
    }
}
