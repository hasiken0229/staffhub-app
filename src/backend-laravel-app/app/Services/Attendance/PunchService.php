<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\CardAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class PunchService extends AttendanceServiceSupport
{
    // Three minutes remains the existing reader debounce. Up to ten minutes a
    // second touch is ambiguous, but a person can still confirm a real clock-out.
    private const SHORT_SHIFT_CONFIRMATION_SECONDS = 600;

    /** Read-only calculation from the employee's schedule and supplied raw punches. */
    public function previewAutomaticPunches(int $employeeId, string $targetDate, ?string $rawClockInAt, ?string $rawClockOutAt, ?int $recordedBreakMinutes = null): array
    {
        $rule = $this->resolveDailyScheduleRule($employeeId, $targetDate);
        $result = $this->automaticPunchResult($rawClockInAt, $rawClockOutAt, $rule, $targetDate, $recordedBreakMinutes);
        unset($result['autoBreak']);

        return $result;
    }

    public function punch(array $payload): array
    {
        $cardUid = strtoupper(trim((string) $payload['cardUid']));
        $occurredAt = CarbonImmutable::parse($payload['occurredAt'])->setTimezone(config('app.timezone', 'Asia/Tokyo'));
        $receivedAt = CarbonImmutable::now($occurredAt->timezone);
        $dedupeKey = (string) $payload['dedupeKey'];

        if (!empty($payload['confirmShortShift'])) {
            return $this->confirmShortShift($payload, $cardUid, $occurredAt, $dedupeKey);
        }

        // Retries are private device operations too. Never reveal an existing event
        // before authenticating the caller and matching the original request.
        $device = $this->authenticateDevice($payload, false);
        $existing = DB::table('attendance_events')
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            $this->assertSamePunch($existing, $device, $cardUid, $occurredAt);
            return $this->formatExistingPunchResult($existing);
        }

        try {
        $result = DB::transaction(function () use ($payload, $cardUid, $occurredAt, $receivedAt, $dedupeKey) {
            // Maintain the shared month -> device/card lock order. A concurrent
            // legitimate retry may still return its original result after close.
            $clockReview = PunchClockReview::requiresReview($occurredAt, $receivedAt, $payload);
            if (!$clockReview) {
                MonthMutationLock::acquire([$occurredAt->format('Y-m')], false);
            }

            $device = $this->authenticateDevice($payload, true);
            $existing = DB::table('attendance_events')->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertSamePunch($existing, $device, $cardUid, $occurredAt);
                return $this->formatExistingPunchResult($existing);
            }
            $clockReview = $clockReview || (!isset($payload['clockReferenceAt']) && !isset($payload['captureAgeSeconds'])
                && PunchClockReview::matchesBadHeartbeat($device, $occurredAt, $receivedAt));
            if (!$clockReview) {
                $this->assertMonthOpen($occurredAt->format('Y-m'));
            }

            $cards = DB::table('employee_cards as c')
                ->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->select([
                    'c.id as card_id',
                    'c.employee_id',
                    'c.is_active',
                    'c.assigned_at',
                    'c.revoked_at',
                    'e.employee_code',
                    'e.name as employee_name',
                    'e.department_name',
                    'e.status as employee_status',
                    'e.joined_on',
                    'e.retired_on',
                ])
                ->whereRaw('upper(c.card_uid) = ?', [$cardUid])
                ->lockForUpdate()
                ->get();

            if ($clockReview) {
                // An unreliable timestamp cannot distinguish two successive card owners.
                $owner = $cards->pluck('employee_id')->unique()->count() === 1
                    ? $cards->first() : (object) ['employee_id' => null];
                return $this->preservePunchForReview($device, $owner, $cardUid, $occurredAt, $dedupeKey, PunchClockReview::REASON, [
                    'serverReceivedAt' => $receivedAt->toIso8601String(),
                    'clockReferenceAt' => $payload['clockReferenceAt'] ?? null,
                    'clientSentAt' => $payload['clientSentAt'] ?? null,
                    'captureAgeSeconds' => $payload['captureAgeSeconds'] ?? null,
                    'captureClockUnknown' => $payload['captureClockUnknown'] ?? false,
                    'heartbeatReceivedAt' => $device->last_seen_at ?? null,
                    'heartbeatClockDriftSeconds' => $device->clock_drift_seconds ?? null,
                ]);
            }

            if ($cards->isEmpty()) {
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

                // Return after the transaction so the rejected event and its dedupe key are
                // committed. The Windows app can then safely remove this specific queued punch.
                return [
                    'errorCode' => 'CARD_NOT_REGISTERED',
                    'errorMessage' => 'このカードは登録されていません。',
                    'errorStatus' => 400,
                ];
            }

            // A queued original belongs to the assignment valid at its original
            // time, never to a card's replacement owner. Ambiguous/missing history
            // is retained without attaching it to any employee's attendance.
            $matchingCards = $cards->filter(fn (object $candidate): bool =>
                $occurredAt->greaterThanOrEqualTo(CarbonImmutable::parse($candidate->assigned_at))
                && (empty($candidate->revoked_at) || $occurredAt->lessThan(CarbonImmutable::parse($candidate->revoked_at)))
            )->values();
            if ($matchingCards->isEmpty()) {
                $priorCards = $cards->filter(fn (object $candidate): bool =>
                    $occurredAt->greaterThanOrEqualTo(CarbonImmutable::parse($candidate->assigned_at))
                )->sortByDesc('assigned_at')->values();
                $lastAssignment = $priorCards->first();
                $uniqueLastAssignment = $lastAssignment !== null && $priorCards
                    ->where('assigned_at', $lastAssignment->assigned_at)->count() === 1;
                if ($uniqueLastAssignment && $this->outsideEmployment($lastAssignment, $occurredAt)) {
                    return $this->preserveRejectedEmploymentPunch($device, $lastAssignment, $cardUid, $occurredAt, $dedupeKey);
                }
            }
            if ($matchingCards->count() !== 1) {
                return $this->preservePunchForReview($device, (object) ['employee_id' => null], $cardUid, $occurredAt, $dedupeKey, 'CARD_ASSIGNMENT_REVIEW_REQUIRED');
            }
            $card = $matchingCards->first();
            if ($this->outsideEmployment($card, $occurredAt)) {
                return $this->preserveRejectedEmploymentPunch($device, $card, $cardUid, $occurredAt, $dedupeKey);
            }
            if (strtoupper((string) $card->employee_status) !== 'ACTIVE' || (int) $card->is_active !== 1 || !empty($card->revoked_at)) {
                return $this->preservePunchForReview($device, $card, $cardUid, $occurredAt, $dedupeKey, 'HISTORICAL_EMPLOYEE_PUNCH');
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
            if ($lastAccepted !== null && $occurredAt->lessThan(CarbonImmutable::parse($lastAccepted->occurred_at))) {
                return $this->preservePunchForReview($device, $card, $cardUid, $occurredAt, $dedupeKey, 'OUT_OF_ORDER_PUNCH');
            }
            $daily = DB::table('attendance_daily')->where('employee_id', $card->employee_id)
                ->where('target_date', $occurredAt->toDateString())->lockForUpdate()->first();
            if ($daily !== null && (($daily->approval_status ?? null) === 'APPROVED'
                || ($daily->close_status ?? null) === 'CLOSED' || !empty($daily->is_manually_edited)
                || !empty($daily->break_overtime_review))) {
                return $this->preservePunchForReview($device, $card, $cardUid, $occurredAt, $dedupeKey, 'CONFIRMED_DAY_PUNCH');
            }
            if (($daily->source_system ?? null) === 'HARMOS'
                && (($daily->source_import_type ?? null) === 'HARMOS_SINGLE_PUNCH_RECONCILIATION'
                    || (!empty($daily->raw_clock_in_at) && !empty($daily->raw_clock_out_at)))
                && $occurredAt->toDateString() < CarbonImmutable::today('Asia/Tokyo')->toDateString()) {
                return $this->preservePunchForReview($device, $card, $cardUid, $occurredAt, $dedupeKey, 'IMPORTED_DAY_PUNCH');
            }

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

            if ($lastAccepted !== null) {
                $lastOccurredAt = CarbonImmutable::parse($lastAccepted->occurred_at);
                $elapsedSeconds = $occurredAt->getTimestamp() - $lastOccurredAt->getTimestamp();
                if ($elapsedSeconds <= 180) {
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
                if ($lastAccepted->event_type === 'CLOCK_IN' && $elapsedSeconds <= self::SHORT_SHIFT_CONFIRMATION_SECONDS) {
                    return $this->preservePunchForReview($device, $card, $cardUid, $occurredAt, $dedupeKey, 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
                }
            }

            $eventType = $lastAccepted === null || $lastAccepted->event_type === 'CLOCK_OUT'
                ? 'CLOCK_IN'
                : 'CLOCK_OUT';

            $resultType = 'SUCCESS';
            $resultMessage = $eventType === 'CLOCK_IN' ? '出勤を記録しました。' : '退勤を記録しました。';
            $warningReason = null;
            if ($eventType === 'CLOCK_IN' && $acceptedToday->isEmpty()) {
                $previousDay = DB::table('attendance_daily')
                    ->where('employee_id', $card->employee_id)
                    ->where('target_date', $occurredAt->subDay()->toDateString())
                    ->first();
                // Calendar-day pairing is deliberate. An unclosed previous day
                // must not turn an ordinary next-day arrival into its clock-out.
                // Effective/manual next-day clock-out means the exception was
                // already resolved by an administrator and needs no new alert.
                if ($previousDay !== null && !empty($previousDay->raw_clock_in_at)
                    && empty($previousDay->raw_clock_out_at) && empty($previousDay->clock_out_at)) {
                    $warningReason = 'PREVIOUS_DAY_OPEN_PUNCH';
                    $resultType = 'WARNING';
                    $resultMessage = '前日の退勤が未記録です。今回の打刻は当日の出勤として保存しました。日跨ぎ勤務の場合は、管理者に勤務開始日の退勤を翌日指定で個別修正するよう依頼してください。';
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
                // ACCEPTED plus a reason retains this warning on exact retries.
                'rejection_reason' => $warningReason,
                'offline_saved' => 0,
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);

            $this->rebuildDaily((int) $card->employee_id, $occurredAt);
            if ($eventType === 'CLOCK_OUT') {
                $this->endShortShiftConfirmations((int) $card->employee_id, $occurredAt);
            }

            $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_ACCEPTED', 'ATTENDANCE_EVENT', (string) $eventId, [
                'employeeId' => (int) $card->employee_id,
                'employeeCode' => $card->employee_code,
                'employeeName' => $card->employee_name,
                'eventType' => $eventType,
                'occurredAt' => $occurredAt->toIso8601String(),
                'warningReason' => $warningReason,
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
        }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // The global key also covers requests arriving through different months
            // or devices. After rollback, resolve only an actual matching key race.
            $device = $this->authenticateDevice($payload, false);
            $existing = DB::table('attendance_events')->where('dedupe_key', $dedupeKey)->first();
            if ($existing === null) { throw $exception; }
            $this->assertSamePunch($existing, $device, $cardUid, $occurredAt);
            return $this->formatExistingPunchResult($existing);
        }

        if (isset($result['errorCode'])) {
            throw new ApiException($result['errorCode'], $result['errorMessage'], (int) $result['errorStatus']);
        }

        return $result;
    }

    private function confirmShortShift(array $payload, string $cardUid, CarbonImmutable $occurredAt, string $dedupeKey): array
    {
        return DB::transaction(function () use ($payload, $cardUid, $occurredAt, $dedupeKey) {
            $month = $occurredAt->format('Y-m');
            MonthMutationLock::acquire([$month], false);
            $device = $this->authenticateDevice($payload, true);
            $event = DB::table('attendance_events')->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
            if ($event === null) {
                throw new ApiException('SHORT_SHIFT_NOT_PENDING', '確認する打刻が見つかりません。退勤は記録していません。管理者に確認を依頼してください。', 409);
            }
            $this->assertSamePunch($event, $device, $cardUid, $occurredAt);
            if ($event->receive_status === 'ACCEPTED' && $event->event_type === 'CLOCK_OUT') {
                return $this->formatExistingPunchResult($event);
            }
            if ($event->rejection_reason === 'SHORT_SHIFT_REVIEW_ONLY') {
                return $this->formatExistingPunchResult($event);
            }
            if ($event->receive_status !== 'REJECTED' || $event->rejection_reason !== 'SHORT_SHIFT_CONFIRMATION_REQUIRED') {
                throw new ApiException('SHORT_SHIFT_NOT_PENDING', 'この打刻は短時間退勤の確認対象ではありません。勤怠履歴を確認してください。', 409);
            }

            $employeeId = (int) $event->employee_id;
            $assignments = DB::table('employee_cards as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->whereRaw('upper(c.card_uid) = ?', [$cardUid])->where('c.is_active', 1)
                ->whereNull('c.revoked_at')->where('c.assigned_at', '<=', $event->occurred_at)
                ->where('e.status', 'ACTIVE')->whereDate('e.joined_on', '<=', $occurredAt->toDateString())
                ->where(fn ($q) => $q->whereNull('e.retired_on')->orWhereDate('e.retired_on', '>=', $occurredAt->toDateString()))
                ->lockForUpdate()->pluck('c.employee_id');
            $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)
                ->where('target_date', $occurredAt->toDateString())->lockForUpdate()->first();
            $canConfirm = $assignments->count() === 1 && (int) $assignments->first() === $employeeId
                && !$this->isMonthClosed($month) && $daily !== null
                && $daily->approval_status !== 'APPROVED' && $daily->close_status !== 'CLOSED'
                && !$daily->is_manually_edited && empty($daily->break_overtime_review)
                && !empty($daily->raw_clock_in_at) && empty($daily->raw_clock_out_at);
            if ($canConfirm) {
                $accepted = DB::table('attendance_events')->where('employee_id', $employeeId)
                    ->where('receive_status', 'ACCEPTED')
                    ->whereBetween('occurred_at', [$occurredAt->startOfDay()->toDateTimeString(), $occurredAt->endOfDay()->toDateTimeString()])
                    ->lockForUpdate()->get();
                $start = $accepted->first();
                $canConfirm = $accepted->count() === 1 && $start->event_type === 'CLOCK_IN'
                    && $start->occurred_at === $daily->raw_clock_in_at && $start->occurred_at < $event->occurred_at
                    && $occurredAt->getTimestamp() - CarbonImmutable::parse($start->occurred_at)->getTimestamp() <= self::SHORT_SHIFT_CONFIRMATION_SECONDS;
            }
            if ($canConfirm) {
                DB::table('attendance_events')->where('id', $event->id)->update([
                    'receive_status' => 'ACCEPTED', 'event_type' => 'CLOCK_OUT', 'rejection_reason' => null,
                ]);
                $this->rebuildDaily($employeeId, $occurredAt);
                $this->endShortShiftConfirmations($employeeId, $occurredAt);
            } else {
                DB::table('attendance_events')->where('id', $event->id)->update(['rejection_reason' => 'SHORT_SHIFT_REVIEW_ONLY']);
            }
            $this->addAudit('DEVICE', (int) $device->id, $canConfirm ? 'SHORT_SHIFT_CONFIRMED' : 'SHORT_SHIFT_CONFIRMATION_SUPERSEDED',
                'ATTENDANCE_EVENT', (string) $event->id, ['employeeId' => $employeeId, 'occurredAt' => $event->occurred_at]);
            return $this->formatExistingPunchResult(DB::table('attendance_events')->where('id', $event->id)->first());
        }, 3);
    }

    private function endShortShiftConfirmations(int $employeeId, CarbonImmutable $clockOut): void
    {
        DB::table('attendance_events')->where('employee_id', $employeeId)
            ->where('rejection_reason', 'SHORT_SHIFT_CONFIRMATION_REQUIRED')
            ->whereBetween('occurred_at', [$clockOut->startOfDay()->toDateTimeString(), $clockOut->endOfDay()->toDateTimeString()])
            ->update(['rejection_reason' => 'SHORT_SHIFT_REVIEW_ONLY']);
    }

    private function assertSamePunch(object $event, object $device, string $cardUid, CarbonImmutable $occurredAt): void
    {
        if ((int) $event->device_id !== (int) $device->id
            || strtoupper(trim((string) $event->card_uid)) !== $cardUid
            || (string) $event->occurred_at !== $occurredAt->format('Y-m-d H:i:s')) {
            throw new ApiException('DEDUPE_CONFLICT', '同じ送信キーに異なる打刻が保存されています。未送信データを保持し、管理者へ連絡してください。', 409);
        }
    }

    private function preservePunchForReview(object $device, object $card, string $cardUid, CarbonImmutable $occurredAt, string $dedupeKey, string $reason, array $details = []): array
    {
        // Retain the original time and key, but do not reinterpret a confirmed day
        // or assign a backward clock-out. REJECTED is the existing reviewable event
        // category; the WARNING response acknowledges storage, not attendance adoption.
        $id = $this->insertAttendanceEvent([
            'employee_id' => $card->employee_id, 'device_id' => $device->id,
            'card_uid' => $cardUid, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'event_type' => null, 'source_type' => 'CARD_READER', 'receive_status' => 'REJECTED',
            'rejection_reason' => $reason, 'offline_saved' => 0, 'dedupe_key' => $dedupeKey, 'created_at' => now(),
        ]);
        $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_REVIEW_REQUIRED', 'ATTENDANCE_EVENT', (string) $id, [
            'employeeId' => $card->employee_id !== null ? (int) $card->employee_id : null, 'reason' => $reason,
            'occurredAt' => $occurredAt->toIso8601String(),
            ...$details,
        ]);
        return $this->formatExistingPunchResult(DB::table('attendance_events')->where('id', $id)->first());
    }

    private function outsideEmployment(object $card, CarbonImmutable $occurredAt): bool
    {
        $date = $occurredAt->toDateString();
        return $date < (string) $card->joined_on
            || (!empty($card->retired_on) && $date > (string) $card->retired_on)
            || (strtoupper((string) $card->employee_status) !== 'ACTIVE' && empty($card->retired_on));
    }

    private function preserveRejectedEmploymentPunch(object $device, object $card, string $cardUid, CarbonImmutable $occurredAt, string $dedupeKey): array
    {
        $id = $this->insertAttendanceEvent([
            'employee_id' => $card->employee_id, 'device_id' => $device->id,
            'card_uid' => $cardUid, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'event_type' => null, 'source_type' => 'CARD_READER', 'receive_status' => 'REJECTED',
            'rejection_reason' => 'EMPLOYEE_NOT_EMPLOYED', 'offline_saved' => 0, 'dedupe_key' => $dedupeKey, 'created_at' => now(),
        ]);
        $this->addAudit('DEVICE', (int) $device->id, 'ATTENDANCE_REJECTED', 'ATTENDANCE_EVENT', (string) $id, [
            'employeeId' => (int) $card->employee_id, 'reason' => 'EMPLOYEE_NOT_EMPLOYED',
            'occurredAt' => $occurredAt->toIso8601String(),
        ]);
        // Acknowledge durable original storage, not attendance adoption. Clients
        // retain a review receipt before removing this item from the offline queue.
        return $this->formatExistingPunchResult(DB::table('attendance_events')->where('id', $id)->first());
    }

    public function heartbeat(array $payload): array
    {
        $clientTimeAt = CarbonImmutable::parse($payload['lastSeenAt']);
        $receivedAt = now();
        $clockDriftSeconds = $clientTimeAt->getTimestamp() - $receivedAt->getTimestamp();

        return DB::transaction(function () use ($payload, $clientTimeAt, $receivedAt, $clockDriftSeconds) {
            $device = $this->authenticateDevice($payload, true);

            DB::table('attendance_devices')
                ->where('id', $device->id)
                ->update(array_intersect_key([
                    'app_version' => $payload['appVersion'] ?? $device->app_version,
                    // The server reception time, not the device's own clock, is used for
                    // terminal liveness. The client time is retained separately for drift checks.
                    'last_seen_at' => $receivedAt->format('Y-m-d H:i:s'),
                    'client_time_at' => $clientTimeAt->format('Y-m-d H:i:s'),
                    'clock_drift_seconds' => $clockDriftSeconds,
                    'pending_offline_count' => (int) $payload['pendingOfflineCount'],
                    'reader_status' => $payload['readerStatus'] ?? ($device->reader_status ?? null),
                    'updated_at' => $receivedAt,
                ], array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('attendance_devices'))));

            return [
                'success' => true,
                'serverTime' => $receivedAt->toIso8601String(),
                'deviceActive' => true,
                'clockDriftSeconds' => $clockDriftSeconds,
            ];
        });
    }

    public function todayDeviceReceipts(array $credentials): array
    {
        $device = $this->authenticateDevice($credentials, false);
        $now = CarbonImmutable::now('Asia/Tokyo');
        $receipts = DB::table('attendance_events as event')
            ->join('employees as employee', 'employee.id', '=', 'event.employee_id')
            ->where('event.device_id', $device->id)
            ->where('event.receive_status', 'ACCEPTED')
            ->whereIn('event.event_type', ['CLOCK_IN', 'CLOCK_OUT'])
            ->where('event.occurred_at', '>=', $now->startOfDay()->toDateTimeString())
            ->where('event.occurred_at', '<', $now->addDay()->startOfDay()->toDateTimeString())
            ->orderByDesc('event.occurred_at')->orderByDesc('event.id')->limit(10)
            ->get(['employee.name as employeeName', 'event.event_type as eventType', 'event.occurred_at as occurredAt'])
            ->map(fn (object $row) => [
                'employeeName' => $row->employeeName, 'eventType' => $row->eventType,
                'occurredAt' => CarbonImmutable::parse($row->occurredAt, 'Asia/Tokyo')->toIso8601String(),
            ])->all();

        return ['checkedAt' => $now->toIso8601String(), 'receipts' => $receipts];
    }

    public function listCardRegistrationEmployees(array $payload): array
    {
        $this->authenticateDevice($payload, false);

        return DB::table('employees')
            ->select(['id', 'employee_code', 'name', 'department_name', 'status'])
            ->where('status', 'ACTIVE')
            ->whereDate('joined_on', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('retired_on')->orWhereDate('retired_on', '>=', now()->toDateString()))
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

        if (trim((string) $device->device_secret_hash) === '' || ! Hash::check((string) $payload['deviceSecret'], $device->device_secret_hash)) {
            throw new ApiException('FORBIDDEN', '端末認証に失敗しました。', 403);
        }

        return $device;
    }
}
