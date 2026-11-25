<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\Attendance\MonthMutationLock;
use App\Services\AuditLogService;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

/** Keeps imported/manual attendance separate from amounts applied by requests. */
final class LeaveAttendanceProjection
{
    public const FIELDS = ['absence_flag', 'special_leave_flag', 'paid_leave_unit', 'hour_paid_leave_minutes', 'child_care_leave_minutes', 'nursing_care_leave_minutes'];

    public function fields(?object $row): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $field === 'paid_leave_unit'
                ? (($row?->{$field} ?? null) === null ? null : (float) $row->{$field})
                : (int) ($row?->{$field} ?? 0);
        }
        return $values;
    }

    public function requests(int $employeeId, string $date): array
    {
        return DB::table('leave_requests')->where('employee_id', $employeeId)->where('status', 'APPROVED')
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->orderBy('id')->get()
            ->filter(fn ($request) => $request->effective_dates === null || in_array($date, LeaveWorkdays::dates($request), true))->values()->all();
    }

    public function applied(array $requests): array
    {
        $values = $this->fields(null);
        foreach ($requests as $r) {
            if (($r->request_category ?? 'LEAVE') === 'TIME_LEAVE') {
                $field = match ($r->time_leave_type) {
                    'PAID_HOURLY' => 'hour_paid_leave_minutes', 'CHILD_CARE_HOURLY' => 'child_care_leave_minutes',
                    'NURSING_CARE_HOURLY' => 'nursing_care_leave_minutes', default => null,
                };
                if ($field) { $values[$field] += (int) $r->quantity_minutes; }
            } elseif (($r->attendance_effect ?? $r->leave_type_code) === 'PAID') {
                $values['paid_leave_unit'] = ($values['paid_leave_unit'] ?? 0) + ($r->day_unit === 'HALF' ? .5 : 1);
            } elseif (($r->attendance_effect ?? $r->leave_type_code) === 'ABSENCE') { $values['absence_flag'] = 1; }
            elseif (($r->attendance_effect ?? $r->leave_type_code) === 'SPECIAL') { $values['special_leave_flag'] = 1; }
        }
        return $values;
    }

    public function combine(array $baseline, array $applied): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $sum = ($baseline[$field] ?? 0) + ($applied[$field] ?? 0);
            $values[$field] = in_array($field, ['absence_flag', 'special_leave_flag'], true) ? min(1, $sum)
                : ($field === 'paid_leave_unit' && $baseline[$field] === null && $applied[$field] === null ? null : $sum);
        }
        if (($values['paid_leave_unit'] ?? 0) > 1) {
            throw new ApiException('LEAVE_OVERLAP', '同日の有給日数が1日を超えます。原本と重複申請を確認してください。', 422);
        }
        return $values;
    }

    /** Call before changing APPROVED state, while month and employee locks are held. */
    public function prepare(int $employeeId, array $dates): void
    {
        foreach ($dates as $date) {
            $current = $this->fields(DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->lockForUpdate()->first());
            $projection = DB::table('attendance_leave_projections')->where('employee_id', $employeeId)->where('target_date', $date)->lockForUpdate()->first();
            if ($projection) {
                if ($current != json_decode($projection->expected_json, true)) {
                    throw new ApiException('LEAVE_PROVENANCE_REVIEW_REQUIRED', $date.' の休暇記録が手修正されています。「日次休暇の原本確認」で確認してください。既存値は変更していません。', 422);
                }
                continue;
            }
            if ($this->requests($employeeId, $date) !== []) {
                throw new ApiException('LEAVE_PROVENANCE_REVIEW_REQUIRED', $date.' の既存承認分と移行分の内訳を「日次休暇の原本確認」で登録してください。既存値は変更していません。', 422);
            }
            DB::table('attendance_leave_projections')->insert([
                'employee_id' => $employeeId, 'target_date' => $date, 'baseline_json' => json_encode($current),
                'applied_json' => json_encode($this->fields(null)), 'expected_json' => json_encode($current), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function sync(int $employeeId, array $dates): void
    {
        foreach ($dates as $date) {
            $p = DB::table('attendance_leave_projections')->where('employee_id', $employeeId)->where('target_date', $date)->first();
            if (!$p) { throw new ApiException('LEAVE_PROVENANCE_REVIEW_REQUIRED', '休暇の原本内訳が未確認です。', 422); }
            $requests = $this->requests($employeeId, $date);
            $applied = $this->applied($requests);
            $existing = DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->first();
            $baseline = json_decode($p->baseline_json, true);
            // Legacy duplicate approvals must be cancellable after explicit source review.
            // Keep the recorded day unchanged until the duplicates have been resolved.
            $unresolved = ($baseline['paid_leave_unit'] ?? 0) + ($applied['paid_leave_unit'] ?? 0) > 1;
            $values = $unresolved ? $this->fields($existing) : $this->combine($baseline, $applied);
            $display = ['request_leave_name' => null, 'request_leave_days' => null];
            foreach ($requests as $request) {
                if ($request->request_category === 'TIME_LEAVE' || $request->attendance_label === null) { continue; }
                $isHalf = $request->day_unit === 'HALF';
                $display['request_leave_name'] = $request->attendance_label.($isHalf ? ($request->half_day_type === 'AM' ? '（午前）' : '（午後）') : '');
                $display['request_leave_days'] = $isHalf ? 0.5 : 1;
            }
            if (!$existing) {
                DB::table('attendance_daily')->insert(['employee_id' => $employeeId, 'target_date' => $date, ...$values, ...$display,
                    'approval_status' => 'APPROVED', 'approval_comment' => '休暇承認により自動反映',
                    'approved_by' => $requests[0]->approved_by ?? null, 'approved_at' => $requests[0]->approved_at ?? null,
                    'updated_at' => now()]);
            } else {
                DB::table('attendance_daily')->where('id', $existing->id)->update([...$values, ...$display, 'updated_at' => now()]);
            }
            DB::table('attendance_leave_projections')->where('id', $p->id)->update([
                'applied_json' => json_encode($applied), 'expected_json' => json_encode($values), 'updated_at' => now(),
            ]);
        }
    }

    public function review(int $employeeId, string $date): array
    {
        if (!DB::table('employees')->where('id', $employeeId)->exists()) { throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404); }
        $current = $this->fields(DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->first());
        $requests = $this->requests($employeeId, $date);
        $p = DB::table('attendance_leave_projections')->where('employee_id', $employeeId)->where('target_date', $date)->first();
        return ['employeeId' => $employeeId, 'targetDate' => $date, 'current' => $current,
            'applied' => $this->applied($requests), 'baseline' => $p ? json_decode($p->baseline_json, true) : null,
            'requiresDuplicateResolution' => ((float) ($p ? json_decode($p->baseline_json, true)['paid_leave_unit'] : 0)) + ($this->applied($requests)['paid_leave_unit'] ?? 0) > 1,
            'requestIds' => array_column($requests, 'id'), 'fingerprint' => hash('sha256', json_encode([$current, $requests, $p]))];
    }

    public function confirmBaseline(array $payload, GenericUser $actor): array
    {
        return DB::transaction(function () use ($payload, $actor) {
            $id = (int) $payload['employeeId']; $date = $payload['targetDate'];
            MonthMutationLock::acquire(MonthMutationLock::months($date));
            DB::table('employees')->where('id', $id)->lockForUpdate()->first();
            $before = $this->review($id, $date);
            if (!hash_equals($before['fingerprint'], $payload['fingerprint'])) {
                throw new ApiException('STALE_LEAVE_PREVIEW', '休暇記録が更新されています。再読込して確認してください。', 409);
            }
            $baseline = $this->fields((object) $payload['baseline']);
            $expected = ($baseline['paid_leave_unit'] ?? 0) + ($before['applied']['paid_leave_unit'] ?? 0) > 1
                ? $before['current'] : $this->combine($baseline, $before['applied']);
            $createdAt = DB::table('attendance_leave_projections')->where('employee_id', $id)->where('target_date', $date)->value('created_at');
            DB::table('attendance_leave_projections')->updateOrInsert(['employee_id' => $id, 'target_date' => $date], [
                'baseline_json' => json_encode($baseline), 'applied_json' => json_encode($before['applied']), 'expected_json' => json_encode($expected),
                'evidence_reference' => $payload['evidenceReference'], 'reviewed_by' => $actor->id, 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
            ]);
            $this->sync($id, [$date]);
            app(AuditLogService::class)->record('ADMIN', $actor->id, 'LEAVE_BASELINE_CONFIRMED', 'EMPLOYEE', (string) $id,
                ['before' => $before, 'baseline' => $baseline, 'after' => $expected, 'evidenceReference' => $payload['evidenceReference']]);
            return $this->review($id, $date);
        });
    }
}
