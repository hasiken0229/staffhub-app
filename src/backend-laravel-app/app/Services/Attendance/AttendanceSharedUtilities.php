<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

trait AttendanceSharedUtilities
{
    protected function addAudit(string $actorType, ?int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }

    protected function combineTimeInput(CarbonImmutable $targetDate, mixed $value, bool $nextDay): ?CarbonImmutable
    {
        $time = trim((string) ($value ?? ''));
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ApiException('VALIDATION_ERROR', '時刻は HH:mm 形式で指定してください。', 422);
        }

        $date = $nextDay ? $targetDate->addDay() : $targetDate;
        return CarbonImmutable::parse($date->toDateString() . ' ' . $time . ':00');
    }

    protected function normalizeBreakPayload(mixed $breaks, CarbonImmutable $targetDate): array
    {
        if (!is_array($breaks)) {
            throw new ApiException('VALIDATION_ERROR', '休憩は配列で指定してください。', 422);
        }

        $items = [];
        foreach ($breaks as $break) {
            if (!is_array($break)) {
                continue;
            }
            $startAt = $this->combineTimeInput($targetDate, $break['startTime'] ?? null, (bool) ($break['startNextDay'] ?? false));
            $endAt = $this->combineTimeInput($targetDate, $break['endTime'] ?? null, (bool) ($break['endNextDay'] ?? false));
            if ($startAt === null && $endAt === null) {
                continue;
            }
            if ($startAt === null || $endAt === null || $endAt->lessThan($startAt)) {
                throw new ApiException('VALIDATION_ERROR', '休憩時間の指定が不正です。', 422, [
                    ['field' => 'breaks', 'message' => '休憩開始と終了を正しい順序で入力してください。'],
                ]);
            }

            $items[] = ['startAt' => $startAt, 'endAt' => $endAt];
        }

        usort($items, static fn (array $a, array $b): int => $a['startAt'] <=> $b['startAt']);
        return $items;
    }

    protected function sumBreakMinutes(array $breaks): int
    {
        $minutes = 0;
        foreach ($breaks as $break) {
            if ($break['startAt'] !== null && $break['endAt'] !== null) {
                $minutes += DailyTimeCalculator::span($break['startAt'], $break['endAt']) ?? 0;
            }
        }

        return $minutes;
    }

    protected function calculateWorkMinutes(?CarbonImmutable $clockInAt, ?CarbonImmutable $clockOutAt, int $breakMinutes): ?int
    {
        return DailyTimeCalculator::actual($clockInAt, $clockOutAt, $breakMinutes);
    }

    protected function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function resolutionKey(int $employeeId, string $targetDate, string $errorCode): string
    {
        return $employeeId . ':' . $targetDate . ':' . strtoupper($errorCode);
    }

    protected function resolveOperatorEmployeeId(GenericUser $actor, int $fallbackEmployeeId): int
    {
        if (strtoupper((string) $actor->role) === 'EMPLOYEE') {
            return (int) $actor->id;
        }

        // users.id and employees.id are different identity namespaces. Only an
        // explicit authenticated link may populate the legacy employee FK.
        $linkedEmployeeId = (int) ($actor->employeeId ?? 0);
        return $linkedEmployeeId > 0 && DB::table('employees')->where('id', $linkedEmployeeId)->exists()
            ? $linkedEmployeeId
            : 0;
    }

    protected function validateTargetMonth(string $targetMonth): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
            throw new ApiException('VALIDATION_ERROR', '対象月は YYYY-MM 形式で指定してください。', 422, [
                ['field' => 'targetMonth', 'message' => '対象月は YYYY-MM 形式で指定してください。'],
            ]);
        }

        return $targetMonth;
    }

    protected function applyTargetMonthFilter(object $query, string $column, string $targetMonth): void
    {
        $monthStart = CarbonImmutable::parse($this->validateTargetMonth($targetMonth) . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $query->whereBetween($column, [
            $monthStart->toDateString(),
            $monthEnd->toDateString(),
        ]);
    }

    protected function assertMonthOpen(string $targetMonth): void
    {
        MonthMutationLock::acquire([$targetMonth]);
        if ($this->isMonthClosed($targetMonth)) {
            throw new ApiException('CLOSED_PERIOD', '対象月は締め済みのため更新できません。', 422, [
                ['field' => 'targetMonth', 'message' => '締め解除後に再度実行してください。'],
            ]);
        }
    }

    protected function isMonthClosed(string $targetMonth): bool
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);

        return DB::table('attendance_monthly_closes')
            ->where('target_year_month', $targetMonth)
            ->where('status', self::MONTH_CLOSE_CLOSED)
            ->exists();
    }
}
