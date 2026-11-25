<?php

namespace Tests\Unit;

use App\Services\Leave\PaidLeaveProjection;
use PHPUnit\Framework\TestCase;

final class PaidLeaveProjectionTest extends TestCase
{
    private function grant(int $id, string $on, ?string $expires, float $days = 10): object
    {
        return (object) ['id' => $id, 'granted_on' => $on, 'expires_on' => $expires, 'granted_days' => $days];
    }

    private function request(string $start, string $end, float $days): object
    {
        return (object) ['id' => 1, 'start_date' => $start, 'end_date' => $end, 'quantity_days' => $days, 'status' => 'APPROVED'];
    }

    public function test_new_year_usage_is_not_charged_to_expired_grant(): void
    {
        $result = (new PaidLeaveProjection)->calculate([
            $this->grant(1, '2025-04-01', '2027-03-31'), $this->grant(2, '2027-04-01', '2029-03-31'),
        ], [], [$this->request('2027-04-02', '2027-04-02', 1)], '2027-04-02');
        $this->assertEquals(9, $result['currentBalance']);
        $this->assertEquals([1 => 0, 2 => 1], $result['usedByGrant']);
        $this->assertEquals(9, end($result['items'])['balanceAfter']);
        $this->assertContains('EXPIRE', array_column($result['items'], 'entryType'));
    }

    public function test_future_grants_adjustments_and_approved_leave_are_separate(): void
    {
        $result = (new PaidLeaveProjection)->calculate([
            $this->grant(1, '2026-09-30', null, 5), $this->grant(2, '2027-04-01', '2029-03-31'),
        ], [(object) ['id' => 1, 'effective_on' => '2027-04-01', 'adjustment_type' => 'ADJUST_PLUS', 'days' => 2]],
            [$this->request('2026-12-31', '2027-01-01', 2)], '2026-12-30');
        $this->assertEquals(5, $result['currentBalance']);
        $this->assertEquals(2, $result['futureApprovedDays']);
        $this->assertCount(1, $result['items']);
    }

    public function test_multi_day_request_is_split_at_expiry_and_as_of_boundary(): void
    {
        $grants = [$this->grant(1, '2025-04-01', '2027-03-31'), $this->grant(2, '2027-04-01', '2029-03-31')];
        $requests = [$this->request('2027-03-31', '2027-04-01', 2)];
        $calculator = new PaidLeaveProjection;
        $march = $calculator->calculate($grants, [], $requests, '2027-03-31');
        $april = $calculator->calculate($grants, [], $requests, '2027-04-01');
        $this->assertEquals(9, $march['currentBalance']);
        $this->assertEquals(1, $march['futureApprovedDays']);
        $this->assertEquals([1 => 1, 2 => 1], $april['usedByGrant']);
        $this->assertEquals(9, $april['currentBalance']);
        $this->assertEquals(-9, array_values(array_filter($april['items'], fn ($e) => $e['entryType'] === 'EXPIRE'))[0]['daysDelta']);
    }

    public function test_missing_expiry_is_not_invented_and_unallocated_use_is_visible(): void
    {
        $result = (new PaidLeaveProjection)->calculate([$this->grant(1, '2026-09-30', null, 1)], [], [$this->request('2027-04-01', '2027-04-01', 2)], '2027-04-01');
        $this->assertEquals(-1, $result['currentBalance']);
        $this->assertEquals(1, end($result['items'])['unallocatedDays']);
        $this->assertNotContains('EXPIRE', array_column($result['items'], 'entryType'));
    }

    public function test_reduction_is_allocated_before_expiry_and_is_not_expired_twice(): void
    {
        $grant = $this->grant(1, '2026-01-01', '2026-10-10');
        $adjustment = (object) ['id' => 1, 'effective_on' => '2026-10-05', 'adjustment_type' => 'ADJUST_MINUS', 'days' => -2];
        $calculator = new PaidLeaveProjection;
        $before = $calculator->calculate([$grant], [$adjustment], [], '2026-10-10');
        $after = $calculator->calculate([$grant], [$adjustment], [], '2026-10-11');
        $this->assertEquals(8, $before['currentBalance']);
        $this->assertEquals([1 => 8], $before['remainingByGrant']);
        $this->assertEquals([1 => 2], $before['adjustedByGrant']);
        $this->assertEquals([1 => 0], $before['usedByGrant']);
        $this->assertEquals(0, $after['currentBalance']);
        $this->assertEquals(-8, end($after['items'])['daysDelta']);
    }

    public function test_reduction_uses_earliest_expiry_then_unknown_without_consuming_future_grants(): void
    {
        $grants = [$this->grant(1, '2026-01-01', null, 5), $this->grant(2, '2026-02-01', '2027-12-31', 5),
            $this->grant(3, '2026-03-01', '2026-10-10', 3), $this->grant(4, '2027-04-01', '2029-03-31', 10)];
        $adjustment = (object) ['id' => 1, 'effective_on' => '2026-10-05', 'adjustment_type' => 'ADJUST_MINUS', 'days' => -9];
        $result = (new PaidLeaveProjection)->calculate($grants, [$adjustment], [], '2026-10-11');
        $this->assertEquals(4, $result['currentBalance']);
        $this->assertEquals([1 => 1, 2 => 5, 3 => 3, 4 => 0], $result['adjustedByGrant']);
        $this->assertEquals([1 => 4, 2 => 0, 3 => 0, 4 => 0], $result['remainingByGrant']);
        $this->assertNotContains('EXPIRE', array_column($result['items'], 'entryType'));
        $this->assertEquals(0, end($result['items'])['unallocatedDays']);
    }

    public function test_excess_reduction_stays_visible_and_is_never_assigned_to_a_future_grant(): void
    {
        $adjustment = (object) ['id' => 1, 'effective_on' => '2026-10-05', 'adjustment_type' => 'ADJUST_MINUS', 'days' => -3];
        $result = (new PaidLeaveProjection)->calculate([$this->grant(1, '2026-01-01', '2026-10-10', 2),
            $this->grant(2, '2027-04-01', '2029-03-31', 10)], [$adjustment], [], '2026-10-11');
        $this->assertEquals(-1, $result['currentBalance']);
        $this->assertEquals([1 => 2, 2 => 0], $result['adjustedByGrant']);
        $this->assertEquals(1, end($result['items'])['unallocatedDays']);
    }
}
