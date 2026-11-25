<?php

namespace Tests\Feature;

use App\Services\Attendance\SaturdayHolidayPolicy as Policy;
use Carbon\CarbonImmutable as Date;
use Tests\TestCase;

final class SaturdayHolidayPolicyTest extends TestCase
{
    public function test_only_elapsed_unpunched_saturdays_are_public_holidays(): void
    {
        Date::setTestNow('2026-10-10 12:00:00');
        try {
            $original=(object)['target_date'=>'2026-09-12','employee_code'=>'970019','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'clock_in_at'=>'2026-09-12 08:00:00','clock_out_at'=>'2026-09-12 15:30:00','work_minutes'=>450,'break_minutes'=>0,'schedule_name'=>'副園長','remark'=>'移行'];
            $result=Policy::project($original);
            $this->assertSame(0,$result->work_minutes);
            $this->assertSame('公休',$result->schedule_name);
            $this->assertNull($result->clock_in_at);
            $this->assertSame(450,$original->work_minutes);
            $this->assertSame($result->remark,Policy::project($result)->remark);
            $long = clone $original;
            $long->remark = str_repeat('備', 255);
            $this->assertSame($long->remark, Policy::project($long)->remark);
            foreach ([['raw_clock_in_at'=>'2026-09-12 08:00:00'],['raw_clock_out_at'=>'2026-09-12 15:30:00'],['is_manually_edited'=>1],['close_status'=>'CLOSED'],['employee_code'=>'970005'],['target_date'=>'2026-08-29'],['target_date'=>'2026-09-11'],['target_date'=>'2026-10-10'],['target_date'=>'2026-10-17']] as $change) {
                $row=(object)array_replace((array)$original,$change);
                $this->assertFalse(Policy::applies($row),json_encode($change));
            }
            foreach (['absence_flag','special_leave_flag','paid_leave_unit','hour_paid_leave_minutes','child_care_leave_minutes','nursing_care_leave_minutes'] as $field) {
                $row=clone $original;$row->$field=1;$this->assertFalse(Policy::applies($row));
            }
            foreach (['子の看護休暇（短）','介護休暇','有休','特別休暇','代休'] as $name) {
                $row=clone $original;$row->schedule_name=$name;$this->assertFalse(Policy::applies($row));
            }
        } finally { Date::setTestNow(); }
    }
}
