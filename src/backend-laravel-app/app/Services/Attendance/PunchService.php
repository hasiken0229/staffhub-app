<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\AuditLogService;
use App\Services\CardAssignmentService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
final class PunchService extends AttendanceServiceSupport
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

        $result = DB::transaction(function () use ($payload, $cardUid, $occurredAt, $dedupeKey) {
            $this->assertMonthOpen($occurredAt->format('Y-m'));

            $device = $this->authenticateDevice($payload, true);

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

            if ($acceptedToday->count() >= 2) {
                $eventId = $this->insertAttendanceEvent([
                    'employee_id' => $card->employee_id,
                    'device_id' => $device->id,
                    'card_uid' => $cardUid,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                    'event_type' => null,
                    'source_type' => 'CARD_READER',
                    'receive_status' => 'REJECTED',
                    'rejection_reason' => 'TOO_MANY_PUNCHES',
                    'offline_saved' => 0,
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                ]);

                $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_REJECTED', 'ATTENDANCE_EVENT', (string) $eventId, [
                    'employeeId' => (int) $card->employee_id,
                    'employeeCode' => $card->employee_code,
                    'employeeName' => $card->employee_name,
                    'reason' => 'TOO_MANY_PUNCHES',
                    'occurredAt' => $occurredAt->toIso8601String(),
                ]);

                return [
                    'errorCode' => 'TOO_MANY_PUNCHES',
                    'errorMessage' => '本日は既に出勤・退勤が記録されています。管理画面で確認してください。',
                    'errorStatus' => 400,
                ];
            }

            $lastAccepted = $acceptedToday->last();
            if ($lastAccepted !== null) {
                $lastOccurredAt = CarbonImmutable::parse($lastAccepted->occurred_at);
                if ($occurredAt->diffInRealSeconds($lastOccurredAt, true) <= 180) {
                    $eventId = $this->insertAttendanceEvent([
                        'employee_id' => $card->employee_id,
                        'device_id' => $device->id,
                        'card_uid' => $cardUid,
                        'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                        'event_type' => null,
                        'source_type' => 'CARD_READER',
                        'receive_status' => 'REJECTED',
                        'rejection_reason' => 'SHORT_INTERVAL_PUNCH',
                        'offline_saved' => 0,
                        'dedupe_key' => $dedupeKey,
                        'created_at' => now(),
                    ]);

                    $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_REJECTED', 'ATTENDANCE_EVENT', (string) $eventId, [
                        'employeeId' => (int) $card->employee_id,
                        'employeeCode' => $card->employee_code,
                        'employeeName' => $card->employee_name,
                        'reason' => 'SHORT_INTERVAL_PUNCH',
                        'previousAttendanceEventId' => (int) $lastAccepted->id,
                        'previousOccurredAt' => CarbonImmutable::parse($lastAccepted->occurred_at)->toIso8601String(),
                        'occurredAt' => $occurredAt->toIso8601String(),
                    ]);

                    return [
                        'errorCode' => 'SHORT_INTERVAL_PUNCH',
                        'errorMessage' => '直前の打刻から3分以内のため、打刻を受け付けできません。',
                        'errorStatus' => 400,
                    ];
                }
            }

            $eventType = $lastAccepted === null || $lastAccepted->event_type === 'CLOCK_OUT'
                ? 'CLOCK_IN'
                : 'CLOCK_OUT';

            $resultType = 'SUCCESS';
            $resultMessage = $eventType === 'CLOCK_IN' ? '出勤を記録しました。' : '退勤を記録しました。';

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

            if ($resultType === 'WARNING') {
                DB::afterCommit(function () use ($card, $device, $eventId, $eventType, $occurredAt): void {
                    app(NotificationMailService::class)->sendAttendanceAlert(
                        'SHORT_INTERVAL',
                        '短時間の連続打刻を検出しました',
                        [
                            trim(($card->employee_name ?? '職員') . 'さんの連続打刻を確認してください。'),
                        ],
                        [
                            '対象職員' => trim(($card->employee_code ?? '') . ' ' . ($card->employee_name ?? '')),
                            '端末' => $device->name ?? null,
                            '打刻種別' => $eventType,
                            '打刻時刻' => $occurredAt->format('Y-m-d H:i:s'),
                            '勤怠イベントID' => (string) $eventId,
                        ],
                    );
                });
            }

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

        if (isset($result['errorCode'])) {
            throw new ApiException($result['errorCode'], $result['errorMessage'], (int) $result['errorStatus']);
        }

        return $result;
    }

    public function heartbeat(array $payload): array
    {
        $lastSeenAt = CarbonImmutable::parse($payload['lastSeenAt']);

        return DB::transaction(function () use ($payload, $lastSeenAt) {
            $device = $this->authenticateDevice($payload, true);

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

    public function listCardRegistrationEmployees(array $payload): array
    {
        $this->authenticateDevice($payload, false);

        return DB::table('employees')
            ->select(['id', 'employee_code', 'name', 'department_name', 'status'])
            ->where('status', 'ACTIVE')
            ->orderBy('employee_code')
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'employeeCode' => $row->employee_code,
                'name' => $row->name,
                'departmentName' => $row->department_name,
                'status' => $row->status,
            ])
            ->all();
    }

    public function assignCardFromDevice(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $device = $this->authenticateDevice($payload, true);

            $result = app(CardAssignmentService::class)->assignFromDevice(
                (int) $payload['employeeId'],
                (string) $payload['cardUid'],
                (int) $device->id,
            );

            $this->addAudit('DEVICE', (int) $device->id, 'CARD_ASSIGNED_FROM_DEVICE', 'CARD', (string) $result['id'], [
                'employeeId' => $result['employeeId'],
                'employeeCode' => $result['employeeCode'],
                'employeeName' => $result['employeeName'],
                'cardUid' => $result['cardUid'],
            ]);

            return $result;
        });
    }

    private function authenticateDevice(array $payload, bool $lockForUpdate): object
    {
        $query = DB::table('attendance_devices')
            ->where('device_code', $payload['deviceCode']);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $device = $query->first();

        if ($device === null || (int) $device->is_active !== 1) {
            throw new ApiException('DEVICE_DISABLED', '端末が無効化されています。', 403);
        }

        if (!empty($device->device_secret_hash) && !Hash::check((string) $payload['deviceSecret'], $device->device_secret_hash)) {
            throw new ApiException('FORBIDDEN', '端末認証に失敗しました。', 403);
        }

        return $device;
    }
}
