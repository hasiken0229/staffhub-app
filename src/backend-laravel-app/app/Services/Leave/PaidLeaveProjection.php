<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

/** Pure, dated ledger projection. No writes and no invented grant/expiry dates. */
final class PaidLeaveProjection
{
    public function calculate(array $grants, array $adjustments, array $requests, string $asOf): array
    {
        $events = [];
        $remaining = $used = $adjusted = [];
        foreach ($grants as $g) {
            $id = (int) $g->id;
            $remaining[$id] = 0;
            $used[$id] = 0;
            $adjusted[$id] = 0;
            $events[] = $this->event('GRANT', 'PAID_LEAVE_GRANT', $id, $g->granted_on, (float) $g->granted_days, $g->note ?? '有給付与');
            if ($g->expires_on !== null) {
                $events[] = $this->event('EXPIRE', 'PAID_LEAVE_GRANT', $id, CarbonImmutable::parse($g->expires_on)->addDay()->toDateString(), 0, '有効期限終了による失効');
            }
        }
        foreach ($adjustments as $a) {
            $events[] = $this->event($a->adjustment_type, 'PAID_LEAVE_ADJUSTMENT', (int) $a->id, $a->effective_on, (float) $a->days, $a->note ?? '有給調整', $a->created_by ?? null);
        }
        $futureReserved = 0;
        foreach ($requests as $r) {
            $hourlyBasis = (int) ($r->hourly_day_minutes ?? 0);
            if ($r->status !== 'APPROVED' || (!$hourlyBasis && (float) $r->quantity_days <= 0)) {
                continue;
            }
            $dates = LeaveWorkdays::dates($r);
            $count = count($dates);
            $total = (int) round((float) $r->quantity_days * 100);
            foreach ($dates as $i => $date) {
                // Use the original minutes and contracted day, independently
                // of the rounded legacy column. Older requests retain their booked units.
                $quantity = $hourlyBasis > 0 ? (int) $r->quantity_minutes * 100 / $hourlyBasis
                    : (isset($r->exact_days) ? $r->exact_days * 100 : intdiv($total, $count) + ($i < $total % $count ? 1 : 0));
                if ($date > $asOf) { $futureReserved += $quantity; }
                $events[] = $this->event('USE', 'LEAVE_REQUEST', (int) $r->id, $date, -$quantity / 100, '有給申請 '.$r->start_date.' - '.$r->end_date, $r->approved_by ?? null);
            }
        }
        $order = ['EXPIRE' => 0, 'GRANT' => 1, 'ADJUST_PLUS' => 2, 'ADJUST_MINUS' => 3, 'USE' => 4];
        usort($events, static fn (array $a, array $b): int => [$a['occurredOn'], $order[$a['entryType']], $a['sourceId']] <=> [$b['occurredOn'], $order[$b['entryType']], $b['sourceId']]);
        usort($grants, static fn (object $a, object $b): int => [$a->granted_on, $a->id] <=> [$b->granted_on, $b->id]);
        $expiryOrderedGrants = $grants;
        // Confirmed operation rule: a reduction consumes the earliest known expiry.
        // Unknown expiry stays unknown and sorts after dated lots; no date is invented.
        usort($expiryOrderedGrants, static fn (object $a, object $b): int =>
            [$a->expires_on ?? '9999-12-31', $a->granted_on, $a->id] <=> [$b->expires_on ?? '9999-12-31', $b->granted_on, $b->id]);
        $balance = 0;
        $items = [];
        foreach ($events as $event) {
            if ($event['occurredOn'] > $asOf) { continue; }
            $id = $event['sourceId'];
            $units = $event['entryType'] === 'USE' ? $event['daysDelta'] * 100 : (int) round($event['daysDelta'] * 100);
            if ($event['entryType'] === 'GRANT') {
                $remaining[$id] = $units;
            } elseif ($event['entryType'] === 'EXPIRE') {
                $units = -$remaining[$id];
                $remaining[$id] = 0;
                if ($units === 0) { continue; }
            } elseif ($event['entryType'] === 'USE' || $event['entryType'] === 'ADJUST_MINUS') {
                $toAllocate = -$units;
                foreach ($event['entryType'] === 'ADJUST_MINUS' ? $expiryOrderedGrants : $grants as $g) {
                    if ($g->granted_on > $event['occurredOn'] || ($g->expires_on !== null && $g->expires_on < $event['occurredOn'])) { continue; }
                    $take = min($toAllocate, $remaining[$g->id]);
                    $remaining[$g->id] -= $take;
                    if ($event['entryType'] === 'USE') { $used[$g->id] += $take; }
                    else { $adjusted[$g->id] += $take; }
                    $toAllocate -= $take;
                }
                // Unallocated usage remains visible in total balance, never
                // charged to a grant that did not exist on the leave date.
                $event['unallocatedDays'] = $toAllocate / 100;
            }
            $balance = round($balance + $units, 10);
            $event['daysDelta'] = $units / 100;
            $event['balanceAfter'] = $balance / 100;
            $event['id'] = count($items) + 1;
            $event['createdAt'] = null; // derived row, not a persisted event timestamp
            $items[] = $event;
        }

        return [
            'asOf' => $asOf, 'currentBalance' => $balance / 100, 'futureApprovedDays' => $futureReserved / 100,
            'usedByGrant' => array_map(static fn (int|float $v): float => round($v / 100, 12), $used),
            'adjustedByGrant' => array_map(static fn (int|float $v): float => round($v / 100, 12), $adjusted),
            'remainingByGrant' => array_map(static fn (int|float $v): float => round($v / 100, 12), $remaining),
            'items' => $items,
        ];
    }

    private function event(string $type, string $source, int $id, string $date, float $delta, ?string $note, ?int $actor = null): array
    {
        return ['entryType' => $type, 'sourceType' => $source, 'sourceId' => $id, 'occurredOn' => $date, 'daysDelta' => $delta, 'note' => $note, 'createdBy' => $actor];
    }
}
