<?php

namespace App\Services\Leave;

/** Read-only reservation check. Actual ledger rows still include approved leave only. */
final class PaidLeaveReservationProjection
{
    public function __construct(private readonly PaidLeaveProjection $ledger) {}

    public function availableAtDate(array $grants, array $adjustments, array $requests, string $date): float
    {
        $requests = $this->reserved($requests);
        $opening = $this->ledger->calculate($grants, $adjustments, $requests, $date)['currentBalance'];
        $minimum = $this->minimumAfter($grants, $adjustments, $requests, $date);
        if ($minimum['balance'] < 0) { return $minimum['balance']; }
        if ($opening <= 0) { return $opening; }
        $canUse = function (float $days) use ($grants, $adjustments, $requests, $date): bool {
            $candidate = $this->candidate($requests, $date, $date, $days);
            $candidate->exact_days = $days;
            return $this->minimumAfter($grants, $adjustments, [...$requests, $candidate], $date)['balance'] >= 0;
        };
        if ($canUse($opening)) { return $opening; }
        $scale = 10000000000;
        $low = 0;
        $high = (int) round($opening * $scale);
        // Consuming a day can free an earlier expiring lot, so subtracting all
        // future reservations from today's balance is not a valid capacity check.
        // Keep the minute-derived fraction when reservations limit today's usable amount.
        while ($low < $high) {
            $units = intdiv($low + $high + 1, 2);
            if ($canUse($units / $scale)) {
                $low = $units;
            } else { $high = $units - 1; }
        }
        return $low / $scale;
    }

    public function insufficientOn(array $grants, array $adjustments, array $requests, string $start, string $end, float $days, ?array $dates = null, ?int $hourlyBasis = null, ?int $minutes = null): ?string
    {
        $requests = $this->reserved($requests);
        $candidate = $this->candidate($requests, $start, $end, $days);
        $candidate->effective_dates = $dates === null ? null : json_encode($dates, JSON_THROW_ON_ERROR);
        $candidate->hourly_day_minutes = $hourlyBasis;
        $candidate->quantity_minutes = $minutes;
        $minimum = $this->minimumAfter($grants, $adjustments, [...$requests, $candidate], $start);
        return $minimum['balance'] < 0 ? $minimum['date'] : null;
    }

    private function reserved(array $requests): array
    {
        return array_values(array_map(static function (object $request): object {
            $copy = clone $request;
            $copy->status = 'APPROVED';
            return $copy;
        }, array_filter($requests, static fn (object $request): bool =>
            in_array($request->status, ['APPROVED', 'PENDING', 'RETURNED'], true) && ((float) $request->quantity_days > 0 || !empty($request->hourly_day_minutes)))));
    }

    private function candidate(array $requests, string $start, string $end, float $days): object
    {
        return (object) ['id'=>max([0, ...array_map(static fn (object $r): int => (int) $r->id, $requests)]) + 1,
            'start_date'=>$start, 'end_date'=>$end, 'quantity_days'=>$days, 'status'=>'APPROVED'];
    }

    private function minimumAfter(array $grants, array $adjustments, array $requests, string $date): array
    {
        $horizon = max([$date,
            ...array_map(static fn (object $r): string => $r->end_date, $requests),
            ...array_map(static fn (object $a): string => $a->effective_on, $adjustments)]);
        $state = $this->ledger->calculate($grants, $adjustments, $requests, $horizon);
        $opening = 0.0;
        $dailyBalances = [];
        $minimumDate = $date;
        foreach ($state['items'] as $entry) {
            if ($entry['occurredOn'] <= $date) { $opening = $entry['balanceAfter']; continue; }
            // A grant on the same day can cover that day's usage. Inspect the
            // final daily balance, not the intermediate pre-grant expiry event.
            $dailyBalances[$entry['occurredOn']] = $entry['balanceAfter'];
        }
        $minimum = $opening;
        foreach ($dailyBalances as $day=>$balance) {
            if ($balance < $minimum) { $minimum = $balance; $minimumDate = $day; }
        }
        return ['balance'=>round($minimum, 10), 'date'=>$minimumDate];
    }
}
