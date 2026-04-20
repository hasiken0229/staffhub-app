<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AttendanceService
{
    public function punch(array $payload): array
    {
        $cardUid = strtoupper(trim((string) $payload['cardUid']));
        $occurredAt = CarbonImmutable::parse($payload['occurredAt']);
        $dedupeKey = (string) $payload['dedupeKey'];

        $existing = DB::table('attendance_events')
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $this->formatExistingPunchResult($existing);
        }

        return DB::transaction(function () use ($payload, $cardUid, $occurredAt, $dedupeKey) {
            $device = DB::table('attendance_devices')
                ->where('device_code', $payload['deviceCode'])
                ->where('is_active', 1)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                throw new ApiException('DEVICE_DISABLED', '端末が無効化されています。', 403);
            }

            if (!empty($device->device_secret_hash) && !Hash::check((string) $payload['deviceSecret'], $device->device_secret_hash)) {
                throw new ApiException('FORBIDDEN', '端末認証に失敗しました。', 403);
            }

            $card = DB::table('employee_cards as c')
                ->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->select([
                    'c.id as card_id',
                    'c.employee_id',
                    'e.employee_code',
                    'e.name as employee_name',
                    'e.department_name',
                ])
                ->whereRaw('upper(c.card_uid) = ?', [$cardUid])
                ->where('c.is_active', 1)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                $eventId = $this->insertAttendanceEvent([
                    'employee_id' => null,
                    'device_id' => $device->id,
                    'card_uid' => $cardUid,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                    'event_type' => null,
                    'source_type' => 'CARD_READER',
                    'receive_status' => 'REJECTED',
                    'rejection_reason' => 'CARD_NOT_REGISTERED',
                    'offline_saved' => 0,
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                ]);

                $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_REJECTED', 'CARD', $cardUid, [
                    'attendanceEventId' => $eventId,
                    'reason' => 'CARD_NOT_REGISTERED',
                ]);

                throw new ApiException('CARD_NOT_REGISTERED', 'このカードは登録されていません。', 400);
            }

            $dayStart = $occurredAt->startOfDay()->format('Y-m-d H:i:s');
            $dayEnd = $occurredAt->endOfDay()->format('Y-m-d H:i:s');

            $acceptedToday = DB::table('attendance_events')
                ->where('employee_id', $card->employee_id)
                ->where('receive_status', 'ACCEPTED')
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])
                ->orderBy('occurred_at')
                ->lockForUpdate()
                ->get();

            $lastAccepted = $acceptedToday->last();
            $eventType = $lastAccepted === null || $lastAccepted->event_type === 'CLOCK_OUT'
                ? 'CLOCK_IN'
                : 'CLOCK_OUT';

            $resultType = 'SUCCESS';
            $resultMessage = $eventType === 'CLOCK_IN' ? '出勤を記録しました。' : '退勤を記録しました。';
            if ($lastAccepted !== null) {
                $lastOccurredAt = CarbonImmutable::parse($lastAccepted->occurred_at);
                if ($occurredAt->diffInRealMinutes($lastOccurredAt, true) <= 2) {
                    $resultType = 'WARNING';
                    $resultMessage = '短時間の連続打刻です。内容を確認してください。';
                }
            }

            $eventId = $this->insertAttendanceEvent([
                'employee_id' => $card->employee_id,
                'device_id' => $device->id,
                'card_uid' => $cardUid,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'event_type' => $eventType,
                'source_type' => 'CARD_READER',
                'receive_status' => 'ACCEPTED',
                'rejection_reason' => null,
                'offline_saved' => 0,
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);

            $this->rebuildDaily((int) $card->employee_id, $occurredAt);

            $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_ACCEPTED', 'ATTENDANCE_EVENT', (string) $eventId, [
                'employeeId' => (int) $card->employee_id,
                'employeeCode' => $card->employee_code,
                'employeeName' => $card->employee_name,
                'eventType' => $eventType,
                'occurredAt' => $occurredAt->toIso8601String(),
            ]);

            return [
                'attendanceEventId' => $eventId,
                'employee' => [
                    'id' => (int) $card->employee_id,
                    'employeeCode' => $card->employee_code,
                    'name' => $card->employee_name,
                ],
                'eventType' => $eventType,
                'resultType' => $resultType,
                'resultMessage' => $resultMessage,
                'occurredAt' => $occurredAt->toIso8601String(),
                'offlineAccepted' => false,
            ];
        });
    }

    public function heartbeat(array $payload): array
    {
        $lastSeenAt = CarbonImmutable::parse($payload['lastSeenAt']);

        return DB::transaction(function () use ($payload, $lastSeenAt) {
            $device = DB::table('attendance_devices')
                ->where('device_code', $payload['deviceCode'])
                ->lockForUpdate()
                ->first();

            if ($device === null || (int) $device->is_active !== 1) {
                throw new ApiException('DEVICE_DISABLED', '端末が無効化されています。', 403);
            }

            if (!empty($device->device_secret_hash) && !Hash::check((string) $payload['deviceSecret'], $device->device_secret_hash)) {
                throw new ApiException('FORBIDDEN', '端末認証に失敗しました。', 403);
            }

            DB::table('attendance_devices')
                ->where('id', $device->id)
                ->update([
                    'app_version' => $payload['appVersion'] ?? $device->app_version,
                    'last_seen_at' => $lastSeenAt->format('Y-m-d H:i:s'),
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'serverTime' => now()->toIso8601String(),
                'deviceActive' => true,
            ];
        });
    }

    public function listEvents(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('attendance_events as ae')
            ->leftJoin('employees as e', 'e.id', '=', 'ae.employee_id')
            ->join('attendance_devices as d', 'd.id', '=', 'ae.device_id')
            ->select([
                'ae.id',
                'ae.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'ae.device_id',
                'd.device_code',
                'd.name as device_name',
                'ae.card_uid',
                'ae.occurred_at',
                'ae.event_type',
                'ae.receive_status',
                'ae.rejection_reason',
                'ae.offline_saved',
            ]);

        if (!empty($filters['from'])) {
            $query->whereDate('ae.occurred_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('ae.occurred_at', '<=', $filters['to']);
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['receiveStatus'])) {
            $query->where('ae.receive_status', strtoupper((string) $filters['receiveStatus']));
        }

        if (!empty($filters['deviceCode'])) {
            $query->where('d.device_code', $filters['deviceCode']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ae.occurred_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapEventRow($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function listDaily(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'ad.target_date',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.work_minutes',
                'ad.absence_flag',
                'ad.special_leave_flag',
                'ad.paid_leave_unit',
            ]);

        if (!empty($filters['targetMonth'])) {
            $query->whereRaw("DATE_FORMAT(ad.target_date, '%Y-%m') = ?", [$filters['targetMonth']]);
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['departmentName'])) {
            $query->where('e.department_name', 'like', '%' . $filters['departmentName'] . '%');
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ad.target_date')
            ->orderBy('e.employee_code')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapDailyRow($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function formatExistingPunchResult(object $event): array
    {
        if ($event->receive_status === 'REJECTED' && $event->rejection_reason === 'CARD_NOT_REGISTERED') {
            throw new ApiException('CARD_NOT_REGISTERED', 'このカードは登録されていません。', 400);
        }

        $employee = null;
        if ($event->employee_id !== null) {
            $employeeRow = DB::table('employees')->where('id', $event->employee_id)->first();
            if ($employeeRow !== null) {
                $employee = [
                    'id' => (int) $employeeRow->id,
                    'employeeCode' => $employeeRow->employee_code,
                    'name' => $employeeRow->name,
                ];
            }
        }

        return [
            'attendanceEventId' => (int) $event->id,
            'employee' => $employee,
            'eventType' => $event->event_type,
            'resultType' => 'SUCCESS',
            'resultMessage' => $event->event_type === 'CLOCK_OUT' ? '退勤を記録しました。' : '出勤を記録しました。',
            'occurredAt' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
            'offlineAccepted' => (bool) $event->offline_saved,
        ];
    }

    private function insertAttendanceEvent(array $attributes): int
    {
        return (int) DB::table('attendance_events')->insertGetId($attributes);
    }

    private function rebuildDaily(int $employeeId, CarbonImmutable $occurredAt): void
    {
        $dayStart = $occurredAt->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd = $occurredAt->endOfDay()->format('Y-m-d H:i:s');
        $targetDate = $occurredAt->toDateString();

        $events = DB::table('attendance_events')
            ->where('employee_id', $employeeId)
            ->where('receive_status', 'ACCEPTED')
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->orderBy('occurred_at')
            ->get();

        $clockInAt = $events->firstWhere('event_type', 'CLOCK_IN')?->occurred_at;
        $clockOutAt = $this->findClockOutAfterClockIn($events, $clockInAt);

        $workMinutes = null;
        if ($clockInAt !== null && $clockOutAt !== null) {
            $workMinutes = CarbonImmutable::parse($clockOutAt)->diffInMinutes(CarbonImmutable::parse($clockInAt), true);
        }

        $existing = DB::table('attendance_daily')
            ->where('employee_id', $employeeId)
            ->where('target_date', $targetDate)
            ->first();

        $values = [
            'employee_id' => $employeeId,
            'target_date' => $targetDate,
            'clock_in_at' => $clockInAt,
            'clock_out_at' => $clockOutAt,
            'work_minutes' => $workMinutes,
            'updated_at' => now(),
        ];

        if ($existing === null) {
            $values += [
                'late_flag' => 0,
                'early_leave_flag' => 0,
                'absence_flag' => 0,
                'special_leave_flag' => 0,
                'paid_leave_unit' => null,
                'remark' => null,
            ];

            DB::table('attendance_daily')->insert($values);
            return;
        }

        DB::table('attendance_daily')
            ->where('id', $existing->id)
            ->update($values);
    }

    private function findClockOutAfterClockIn(Collection $events, ?string $clockInAt): ?string
    {
        $clockOut = null;

        foreach ($events as $event) {
            if ($event->event_type !== 'CLOCK_OUT') {
                continue;
            }

            if ($clockInAt === null || CarbonImmutable::parse($event->occurred_at)->greaterThanOrEqualTo(CarbonImmutable::parse($clockInAt))) {
                $clockOut = $event->occurred_at;
            }
        }

        return $clockOut;
    }

    private function addAudit(string $actorType, ?int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'ip_address' => request()?->ip(),
        ]);
    }

    private function mapEventRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employeeId' => $row->employee_id !== null ? (int) $row->employee_id : null,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'deviceId' => (int) $row->device_id,
            'deviceCode' => $row->device_code,
            'deviceName' => $row->device_name,
            'cardUid' => $row->card_uid,
            'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'eventType' => $row->event_type,
            'receiveStatus' => $row->receive_status,
            'rejectionReason' => $row->rejection_reason,
            'offlineSaved' => (bool) $row->offline_saved,
        ];
    }

    private function mapDailyRow(object $row): array
    {
        return [
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'targetDate' => $row->target_date,
            'clockInAt' => $row->clock_in_at ? CarbonImmutable::parse($row->clock_in_at)->toIso8601String() : null,
            'clockOutAt' => $row->clock_out_at ? CarbonImmutable::parse($row->clock_out_at)->toIso8601String() : null,
            'workMinutes' => $row->work_minutes !== null ? (int) $row->work_minutes : null,
            'absenceFlag' => (bool) $row->absence_flag,
            'specialLeaveFlag' => (bool) $row->special_leave_flag,
            'paidLeaveUnit' => $row->paid_leave_unit !== null ? (float) $row->paid_leave_unit : null,
        ];
    }
}
