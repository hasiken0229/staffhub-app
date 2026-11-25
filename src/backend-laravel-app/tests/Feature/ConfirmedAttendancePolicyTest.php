<?php

namespace Tests\Feature;

use App\Services\Attendance\ConfirmedAttendancePolicy as Policy;
use App\Services\Attendance\AttendancePunchRebuilder;
use Carbon\CarbonImmutable as Date;
use Tests\TestCase;

final class ConfirmedAttendancePolicyTest extends TestCase
{
    public function test_schedule_fallback_is_only_for_one_missing_past_punch(): void
    {
        Date::setTestNow('2026-10-08 12:00:00');
        try {
            $helper=new class { use AttendancePunchRebuilder;
                public function pair($in,$out,$rule,$date='2026-09-03') { return $this->resolveEffectivePunches($in,$out,$rule,$date); }
            };
            $rule=['allowScheduledFallback'=>true,'policyKind'=>'REGULAR','scheduledClockIn'=>'07:45','scheduledClockOut'=>'16:30','includeBeforeStart'=>false,'includeAfterEnd'=>true];
            $this->assertSame(['clockInAt'=>'2026-09-03 07:45:00','clockOutAt'=>'2026-09-03 16:30:00','inferredSide'=>'CLOCK_OUT'],$helper->pair('2026-09-03 07:38:01',null,$rule));
            $this->assertSame('CLOCK_IN',$helper->pair(null,'2026-09-03 16:33:00',$rule)['inferredSide']);
            $this->assertNull($helper->pair(null,null,$rule)['inferredSide']);
            $this->assertNull($helper->pair('2026-10-08 07:38:01',null,$rule,'2026-10-08')['clockOutAt']);
            $this->assertSame(['clockInAt'=>'2026-09-03 07:45:00','clockOutAt'=>'2026-09-03 17:00:00','inferredSide'=>'CLOCK_IN'],$helper->pair('2026-09-03 17:00:00',null,$rule));
            $this->assertSame(['clockInAt'=>'2026-09-03 07:45:00','clockOutAt'=>'2026-09-03 16:30:00','inferredSide'=>'CLOCK_OUT'],$helper->pair(null,'2026-09-03 07:00:00',$rule));
            $this->assertNull($helper->pair('2026-09-03 12:07:30',null,$rule)['inferredSide']);
            $rule['scheduledClockOut']=null;
            $this->assertNull($helper->pair('2026-09-03 07:38:01',null,$rule)['clockOutAt']);
            $rule['scheduledClockOut']='16:30';$rule['allowScheduledFallback']=false;
            $this->assertNull($helper->pair('2026-09-03 07:38:01',null,$rule)['clockOutAt']);
        } finally { Date::setTestNow(); }
    }

    public function test_employee_970017_has_no_break_and_clips_both_ends_without_filling_late_arrival(): void
    {
        $p = Policy::profile((object) ['employee_code'=>'970017', 'employment_type'=>'FULL_TIME'], '2026-09-07');
        $this->assertSame('REGULAR_SHORT_TIME', $p['kind']);
        $this->assertFalse($p['includeBeforeStart']);
        $this->assertFalse($p['includeAfterEnd']);
        $helper = new class { use AttendancePunchRebuilder;
            public function calculate($in, $out, $rule) {
                $date = '2026-09-07';
                $start = Date::parse($this->applyClockInRule($in, $rule, $date));
                $end = Date::parse($this->applyClockOutRule($out, $rule, $date));
                $break = $this->buildAutoBreak($start, $end, $rule, $date);
                return [$start->format('H:i:s'), $end->format('H:i:s'), $break, \App\Services\Attendance\DailyTimeCalculator::actual($start, $end, $break['minutes'])];
            }
        };
        $rule = ['policyKind'=>$p['kind'], 'scheduledClockIn'=>'08:30', 'scheduledClockOut'=>'14:30', 'includeBeforeStart'=>false, 'includeAfterEnd'=>false];
        $this->assertSame(['08:30:00', '14:30:00', ['breaks'=>[], 'minutes'=>0], 360], $helper->calculate('2026-09-07 08:26:23', '2026-09-07 14:32:09', $rule));
        $this->assertSame(['09:31:37', '14:30:00', ['breaks'=>[], 'minutes'=>0], 298], $helper->calculate('2026-09-07 09:31:37', '2026-09-07 14:55:44', $rule));
        $this->assertSame(['08:30:00', '13:00:00', ['breaks'=>[], 'minutes'=>0], 270], $helper->calculate('2026-09-07 08:25:00', '2026-09-07 13:00:00', $rule));
        $this->assertNull(Policy::profile((object) ['employee_code'=>'970017', 'employment_type'=>'FULL_TIME'], '2026-08-31'));
        $this->assertSame('REGULAR', Policy::profile((object) ['employee_code'=>'970031', 'employment_type'=>'FULL_TIME'], '2026-09-07')['kind']);
    }

    public function test_part_time_thresholds_apply_to_accepted_span_before_breaks(): void
    {
        foreach (['15:00:00' => 0, '15:00:01' => 45, '18:00:00' => 45, '18:00:01' => 60] as $end => $expected) {
            $r = Policy::breaks(Date::parse('2026-09-01 09:00'), Date::parse('2026-09-01 '.$end), ['policyKind'=>'PART_TIME'], '2026-09-01');
            $this->assertSame($expected, $r['minutes']);
        }
        $this->assertNull(Policy::breaks(Date::parse('2026-09-01 09:00'), null, ['policyKind'=>'PART_TIME'], '2026-09-01'));
    }

    public function test_regular_lunch_and_post_schedule_break_only_deduct_overlap(): void
    {
        foreach (['17:00'=>45,'17:10'=>55,'17:30'=>60] as $end=>$expected) {
            $r=Policy::breaks(Date::parse('2026-09-01 09:00'), Date::parse('2026-09-01 '.$end), ['policyKind'=>'REGULAR','scheduledClockOut'=>'17:00'], '2026-09-01');
            $this->assertSame($expected,$r['minutes']);
        }
        $r=Policy::breaks(Date::parse('2026-09-01 12:30'),Date::parse('2026-09-01 13:00'),['policyKind'=>'REGULAR','scheduledClockOut'=>'12:30'],'2026-09-01');
        $this->assertSame(15,$r['minutes']); // No double counting when break windows overlap.
    }

    public function test_exceptions_and_unconfirmed_manager_scope(): void
    {
        foreach (['970035','970029','970001'] as $code) {
            $p=Policy::profile((object)['employee_code'=>$code,'employment_type'=>'PART_TIME'],'2026-09-01');
            $this->assertTrue($p['includeBeforeStart']);$this->assertTrue($p['includeAfterEnd']);
        }
        $this->assertNull(Policy::profile((object)['employment_type'=>'FULL_TIME','is_management_supervisor'=>true],'2026-09-01'));
        $this->assertNull(Policy::profile((object)['employment_type'=>'FULL_TIME'],'2026-08-31'));
        $this->assertSame(0,Policy::overtime(['employmentType'=>'扶養内','targetDate'=>'2026-09-01'],600));
        $this->assertSame(120,Policy::overtime(['employmentType'=>'FULL_TIME','targetDate'=>'2026-09-01'],600));
    }

    public function test_end_clipping_and_start_clipping_leave_raw_input_unchanged(): void
    {
        $helper=new class { use AttendancePunchRebuilder;
            public function bounds($start,$end,$rule) {return [$this->applyClockInRule($start,$rule,'2026-09-07'),$this->applyClockOutRule($end,$rule,'2026-09-07')];}
        };
        $rule=['policyKind'=>'PART_TIME','scheduledClockIn'=>'08:30','scheduledClockOut'=>'14:30','includeBeforeStart'=>false,'includeAfterEnd'=>false];
        $this->assertSame(['2026-09-07 08:30:00','2026-09-07 14:30:00'],$helper->bounds('2026-09-07 08:26:23','2026-09-07 14:32:09',$rule));
        $rule['includeBeforeStart']=true;$rule['includeAfterEnd']=true;
        $this->assertSame(['2026-09-07 08:26:23','2026-09-07 14:32:09'],$helper->bounds('2026-09-07 08:26:23','2026-09-07 14:32:09',$rule));
    }
}
