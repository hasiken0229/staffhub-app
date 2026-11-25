<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use App\Services\Attendance\BreakOvertimeReviewService;
use App\Services\Attendance\MonthMutationLock;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function devices()
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('attendance_devices');
        $diagnosticsSchemaReady = array_diff(['client_time_at', 'clock_drift_seconds', 'pending_offline_count', 'reader_status'], $columns) === [];
        $fields = [
                'id',
                'device_code',
                'name',
                'location_name',
                'app_version',
                'last_seen_at',
                'client_time_at',
                'clock_drift_seconds',
                'pending_offline_count',
                'reader_status',
                'is_active',
            ];
        // Older installations do not have heartbeat detail columns. Keep their DB unchanged.
        $rows = DB::table('attendance_devices')
            ->select(array_map(static fn ($field) => in_array($field, $columns, true) ? $field : DB::raw('NULL AS '.$field), $fields))
            ->orderBy('device_code')
            ->get();

        return ApiResponse::ok($rows->map(static fn (object $row) => [
            'id' => (int) $row->id,
            'deviceCode' => (string) $row->device_code,
            'name' => (string) $row->name,
            'locationName' => $row->location_name,
            'appVersion' => $row->app_version,
            'lastSeenAt' => $row->last_seen_at ? CarbonImmutable::parse((string) $row->last_seen_at)->toIso8601String() : null,
            'clientTimeAt' => $row->client_time_at ? CarbonImmutable::parse((string) $row->client_time_at)->toIso8601String() : null,
            'clockDriftSeconds' => $row->client_time_at !== null && $row->clock_drift_seconds !== null ? (int) $row->clock_drift_seconds : null,
            'pendingOfflineCount' => $row->client_time_at !== null && $row->pending_offline_count !== null ? (int) $row->pending_offline_count : null,
            'readerStatus' => $row->client_time_at !== null ? $row->reader_status : null,
            'diagnosticsSchemaReady' => $diagnosticsSchemaReady,
            'isActive' => (bool) $row->is_active,
        ])->all());
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
        $request->validate(['periodBasis' => ['nullable', 'in:CALENDAR,PAYROLL']]);
        $result = $this->attendanceService->listDailyGrid($request->only([
            'targetMonth',
            'employeeCode',
            'departmentName',
            'periodBasis',
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

    public function storeDaily(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer'],
            'targetDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->createDaily((int) $payload['employeeId'], (string) $payload['targetDate'], $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function breakOvertime(Request $request, int $id, string $operation, BreakOvertimeReviewService $service)
    {
        $input = $request->validate([
            'plannedStart' => ['nullable', 'date_format:H:i'],
            'startBasis' => ['nullable', 'in:RULE,ACTUAL'],
            'actualStart' => ['nullable', 'date_format:H:i'],
            'actualStartNextDay' => ['nullable', 'boolean'],
            'actualBreakMinutes' => ['nullable', 'integer', 'between:0,1440'],
            'breakBasis' => ['nullable', 'in:SCHEDULE_END,REGULAR_PUNCH'],
            'confirmed' => ['nullable', 'boolean'],
            'token' => ['nullable', 'string', 'max:256'],
        ]);
        try {
            $result = $operation === 'apply'
                ? $service->apply($id, $input, $request->user())
                : $service->preview($id, $input, $request->user());
            return ApiResponse::ok($result);
        } catch (ApiException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
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
            'remark' => ['nullable', 'string', 'max:255'],
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

    public function employeeSettings()
    {
        $rows = DB::table('employees as e')
            ->leftJoin('employee_attendance_settings as eas', 'eas.employee_id', '=', 'e.id')
            ->select([
                'e.id as employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.status',
                'eas.standard_clock_in_time',
                'eas.standard_clock_out_time',
                'eas.include_before_start',
                'eas.include_after_end',
            ])
            ->orderBy('e.employee_code')
            ->get();

        return ApiResponse::ok($rows->map(fn (object $row) => [
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'departmentName' => $row->department_name,
            'locationName' => $row->location_name,
            'status' => $row->status,
            'standardClockInTime' => $this->formatClockSetting($row->standard_clock_in_time),
            'standardClockOutTime' => $this->formatClockSetting($row->standard_clock_out_time),
            'includeBeforeStart' => (bool) ($row->include_before_start ?? false),
            'includeAfterEnd' => (bool) ($row->include_after_end ?? false),
        ])->all());
    }

    public function saveEmployeeSetting(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'standardClockInTime' => ['nullable', 'date_format:H:i'],
            'standardClockOutTime' => ['nullable', 'date_format:H:i'],
            'includeBeforeStart' => ['nullable', 'boolean'],
            'includeAfterEnd' => ['nullable', 'boolean'],
        ]);

        DB::table('employee_attendance_settings')->updateOrInsert(
            ['employee_id' => (int) $payload['employeeId']],
            [
                'standard_clock_in_time' => $payload['standardClockInTime'] ?? null,
                'standard_clock_out_time' => $payload['standardClockOutTime'] ?? null,
                'include_before_start' => (bool) ($payload['includeBeforeStart'] ?? false),
                'include_after_end' => (bool) ($payload['includeAfterEnd'] ?? false),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->employeeSettings();
    }

    public function breakRules()
    {
        $row = DB::table('attendance_break_rules')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($row === null) {
            DB::table('attendance_break_rules')->insert([
                'name' => '園標準',
                'base_break_minutes' => 45,
                'threshold_work_minutes' => 480,
                'threshold_break_minutes' => 60,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('attendance_break_rules')->where('is_active', true)->orderBy('id')->first();
        }

        return ApiResponse::ok($this->mapBreakRule($row));
    }

    public function saveBreakRule(Request $request)
    {
        $payload = $request->validate([
            'baseBreakMinutes' => ['required', 'integer', 'min:0', 'max:240'],
            'thresholdWorkMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'thresholdBreakMinutes' => ['required', 'integer', 'min:0', 'max:240'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $row = DB::table('attendance_break_rules')->where('is_active', true)->orderBy('id')->first();
        $values = [
            'name' => '園標準',
            'base_break_minutes' => (int) $payload['baseBreakMinutes'],
            'threshold_work_minutes' => (int) $payload['thresholdWorkMinutes'],
            'threshold_break_minutes' => (int) $payload['thresholdBreakMinutes'],
            'note' => $payload['note'] ?? null,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($row === null) {
            $values['created_at'] = now();
            DB::table('attendance_break_rules')->insert($values);
        } else {
            DB::table('attendance_break_rules')->where('id', $row->id)->update($values);
        }

        return $this->breakRules();
    }

    public function shiftSchedules(Request $request)
    {
        $payload = $request->validate([
            'targetMonth' => ['nullable', 'date_format:Y-m'],
            'employeeId' => ['nullable', 'integer'],
        ]);
        $targetMonth = $payload['targetMonth'] ?? now()->format('Y-m');

        $query = DB::table('attendance_shift_schedules as ass')
            ->join('employees as e', 'e.id', '=', 'ass.employee_id')
            ->leftJoin('work_type_settings as wt', 'wt.id', '=', 'ass.work_type_id')
            ->whereBetween('ass.target_date', [$targetMonth . '-01', date('Y-m-t', strtotime($targetMonth . '-01'))])
            ->select([
                'ass.id',
                'ass.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'ass.target_date',
                'ass.work_type_id',
                'wt.name as work_type_name',
                'ass.scheduled_clock_in_time',
                'ass.scheduled_clock_out_time',
                'ass.note',
            ]);

        if (!empty($payload['employeeId'])) {
            $query->where('ass.employee_id', (int) $payload['employeeId']);
        }

        $rows = $query
            ->orderBy('e.employee_code')
            ->orderBy('ass.target_date')
            ->get();

        return ApiResponse::ok($rows->map(fn (object $row) => $this->mapShiftSchedule($row))->all());
    }

    public function saveShiftSchedule(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'targetDate' => ['required', 'date_format:Y-m-d'],
            'workTypeId' => ['nullable', 'integer', 'exists:work_type_settings,id'],
            'scheduledClockInTime' => ['nullable', 'date_format:H:i'],
            'scheduledClockOutTime' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            DB::transaction(function () use ($payload, $request): void {
                $date = (string) $payload['targetDate'];
                $employeeId = (int) $payload['employeeId'];
                // Serialize with punching, corrections, bulk approval and month close.
                MonthMutationLock::acquire([substr($date, 0, 7)]);
                $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)
                    ->where('target_date', $date)->lockForUpdate()->first();
                if (($daily->close_status ?? 'OPEN') === 'CLOSED') {
                    throw new ApiException('CLOSED_PERIOD', '日次勤怠が締め済みのためシフトを変更できません。', 422);
                }

                $setting = DB::table('employee_attendance_settings')->where('employee_id', $employeeId)->first();
                $clockIn = $payload['scheduledClockInTime'] ?? null;
                $clockOut = $payload['scheduledClockOutTime'] ?? null;
                $effectiveIn = $clockIn ?? $setting?->standard_clock_in_time;
                $effectiveOut = $clockOut ?? $setting?->standard_clock_out_time;
                if ($effectiveIn !== null && $effectiveOut !== null) {
                    // This is the existing attendance-day boundary: 00:00–05:00 is next day.
                    $minutes = static function (string $time): int {
                        $value = (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
                        return $value <= 300 ? $value + 1440 : $value;
                    };
                    if ($minutes($effectiveOut) <= $minutes($effectiveIn)) {
                        throw new ApiException('VALIDATION_ERROR', '予定退勤は予定出勤より後にしてください。00:00〜05:00は翌日扱いです。', 422, [
                            ['field' => 'scheduledClockOutTime', 'message' => '予定出勤より後の時刻を指定してください。00:00〜05:00は翌日扱いです。'],
                        ]);
                    }
                }

                $existing = DB::table('attendance_shift_schedules')->where('employee_id', $employeeId)
                    ->where('target_date', $date)->lockForUpdate()->first();
                $values = [
                    'work_type_id' => $payload['workTypeId'] ?? null,
                    'scheduled_clock_in_time' => $clockIn !== null ? $clockIn.':00' : null,
                    'scheduled_clock_out_time' => $clockOut !== null ? $clockOut.':00' : null,
                    'note' => $payload['note'] ?? null,
                ];
                if ($existing !== null) {
                    $changed = collect($values)->contains(function ($value, $key) use ($existing): bool {
                        $before = $existing->{$key};
                        if (str_starts_with($key, 'scheduled_clock_') && $before !== null) {
                            $before = substr($before, 0, 5).':00';
                        }
                        return $before != $value;
                    });
                    if (!$changed) { return; }
                    $id = (int) $existing->id;
                    DB::table('attendance_shift_schedules')->where('id', $id)->update([...$values, 'updated_at' => now()]);
                } else {
                    $id = (int) DB::table('attendance_shift_schedules')->insertGetId([
                        ...$values, 'employee_id' => $employeeId, 'target_date' => $date,
                        'created_by' => null, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                app(AuditLogService::class)->record('ADMIN', (int) $request->user()->id,
                    'ATTENDANCE_SHIFT_SAVED', 'ATTENDANCE_SHIFT', (string) $id,
                    ['before' => $existing !== null ? (array) $existing : null,
                     'after' => (array) DB::table('attendance_shift_schedules')->where('id', $id)->first()]);
                // Schedules do not rewrite raw punches, imported values or approved/manual daily records.
            });
        } catch (ApiException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }

        return $this->shiftSchedules(new Request([
            'targetMonth' => substr((string) $payload['targetDate'], 0, 7),
            'employeeId' => (int) $payload['employeeId'],
        ]));
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

    private function formatClockSetting(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr($value, 0, 5);
    }

    private function mapBreakRule(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'baseBreakMinutes' => (int) $row->base_break_minutes,
            'thresholdWorkMinutes' => (int) $row->threshold_work_minutes,
            'thresholdBreakMinutes' => (int) $row->threshold_break_minutes,
            'note' => $row->note,
        ];
    }

    private function mapShiftSchedule(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'targetDate' => (string) $row->target_date,
            'workTypeId' => $row->work_type_id !== null ? (int) $row->work_type_id : null,
            'workTypeName' => $row->work_type_name,
            'scheduledClockInTime' => $this->formatClockSetting($row->scheduled_clock_in_time),
            'scheduledClockOutTime' => $this->formatClockSetting($row->scheduled_clock_out_time),
            'note' => $row->note,
        ];
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

    public function monthApprovalPreview(Request $request)
    {
        $payload = $request->validate(['targetMonth'=>['required', 'date_format:Y-m']]);
        return ApiResponse::ok(app(\App\Services\Attendance\MonthApprovalService::class)->preview($payload['targetMonth']));
    }

    public function approveMonthCandidates(Request $request)
    {
        $payload = $request->validate([
            'targetMonth'=>['required', 'date_format:Y-m'],
            'candidates'=>['required', 'array', 'min:1', 'max:2000'],
            'candidates.*.id'=>['required', 'integer', 'min:1', 'distinct'],
            'candidates.*.fingerprint'=>['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'comment'=>['nullable', 'string', 'max:2000'],
        ]);
        return ApiResponse::ok(app(\App\Services\Attendance\MonthApprovalService::class)->approve($payload['targetMonth'], $payload['candidates'], $payload['comment'] ?? null, $request->user()));
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
