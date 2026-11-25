<?php

namespace Tests\Unit;

use App\Services\Attendance\BreakReviewPolicy;
use PHPUnit\Framework\TestCase;

final class BreakReviewPolicyTest extends TestCase
{
    public function test_strict_work_thresholds_and_break_shortage_are_review_guidance_only(): void
    {
        foreach ([
            [null, 0, null, null, false],
            [360, 0, 0, 0, false],
            [361, 44, 45, 1, true],
            [361, 45, 45, 0, false],
            [480, 45, 45, 0, false],
            [481, 45, 60, 15, true],
            [481, 59, 60, 1, true],
            [481, 60, 60, 0, false],
        ] as [$work, $break, $required, $shortage, $needsConfirmation]) {
            $row = (object) ['work_minutes' => $work, 'break_minutes' => $break, 'clock_in_at' => '2026-10-02 09:00:00', 'clock_out_at' => '2026-10-02 18:00:00'];
            $before = clone $row;
            $review = BreakReviewPolicy::review($row);
            $this->assertSame($required, $review['requiredMinutes']);
            $this->assertSame($shortage, $review['shortageMinutes']);
            $this->assertSame($needsConfirmation, $review['needsConfirmation']);
            $this->assertSame('UNCONFIRMED', $review['actualBreakStatus']);
            $this->assertSame($break, $review['recordedMinutes']);
            $this->assertEquals($before, $row);
            if ($needsConfirmation) {
                $this->assertStringContainsString('休憩不足を確定する表示ではありません', $review['message']);
            } else {
                $this->assertNull($review['message']);
            }
        }
    }

    public function test_origins_never_claim_observed_or_confirmed_breaks(): void
    {
        $base = ['work_minutes' => 495, 'break_minutes' => 45, 'clock_in_at' => '2026-10-02 09:00:00', 'clock_out_at' => '2026-10-02 18:00:00'];
        foreach ([
            [[], 'AUTOMATIC'],
            [['source_system' => 'HARMOS'], 'IMPORTED'],
            [['is_manually_edited' => true], 'MANUAL_OR_RETAINED'],
            [['is_manually_edited' => true, 'source_system' => 'HARMOS'], 'MANUAL_OR_RETAINED'],
            [['clock_out_at' => null, 'work_minutes' => null], 'UNDETERMINED'],
        ] as [$override, $source]) {
            $review = BreakReviewPolicy::review((object) array_merge($base, $override));
            $this->assertSame($source, $review['source']);
            $this->assertSame('UNCONFIRMED', $review['actualBreakStatus']);
        }
    }

    public function test_imported_work_is_not_recomputed_from_the_adopted_span(): void
    {
        $review = BreakReviewPolicy::review((object) ['source_system' => 'HARMOS', 'work_minutes' => 360, 'break_minutes' => 0, 'clock_in_at' => '2026-10-02 08:00:00', 'clock_out_at' => '2026-10-02 18:00:00']);
        $this->assertSame(0, $review['requiredMinutes']);
        $this->assertFalse($review['needsConfirmation']);
    }

    public function test_unknown_or_management_scope_is_a_candidate_without_deciding_applicability(): void
    {
        $review = BreakReviewPolicy::review((object) ['employment_type'=>'管理監督者','is_management_supervisor'=>true,'work_minutes'=>481,'break_minutes'=>45]);
        $this->assertTrue($review['needsConfirmation']);
        $this->assertStringContainsString('一般的な休憩基準による確認候補', $review['message']);
        $this->assertStringContainsString('適用区分', $review['message']);
        $this->assertSame('UNCONFIRMED', $review['actualBreakStatus']);
    }
}
