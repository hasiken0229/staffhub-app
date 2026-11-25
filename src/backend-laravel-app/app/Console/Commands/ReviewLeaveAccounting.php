<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveWorkdays;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReviewLeaveAccounting extends Command
{
    protected $signature = 'leave:review-accounting {--employee= : 対象職員ID}';
    protected $description = '旧方式の承認済み休暇を照合し、原本確認が必要な申請を一覧表示する';

    public function handle(): int
    {
        $employee = $this->option('employee');
        if ($employee !== null && (!ctype_digit((string) $employee) || (int) $employee < 1)) {
            $this->error('対象職員IDは正の整数で指定してください。');
            return self::INVALID;
        }
        $query = DB::table('leave_requests')->where('status', 'APPROVED')->whereNull('attendance_effect')
            ->when($employee !== null, fn ($q) => $q->where('employee_id', (int) $employee));
        $count = 0;
        foreach ($query->lazyById(200) as $r) {
            $reason = null;
            if (!in_array($r->leave_type_code, ['PAID', 'SPECIAL', 'ABSENCE'], true)) {
                $reason = '独自区分 '.$r->leave_type_code.'。当時の勤怠区分・消化先を原本で確認';
            } elseif ($r->time_leave_type === 'PAID_HOURLY' && (float) $r->quantity_days > 0) {
                $reason = '日数換算の記録 '.$r->quantity_days.'日／'.$r->quantity_minutes.'分。当時の所定時間を原本で確認';
            } elseif ($r->time_leave_type === 'PAID_HOURLY' && $r->quantity_minutes && !DB::table('paid_leave_hourly_balances')
                ->where('employee_id', $r->employee_id)->where('effective_on', '<', $r->start_date)->exists()) {
                $reason = '日数換算0日／'.$r->quantity_minutes.'分。別枠付与と当時の所定時間を原本で確認';
            } elseif ($r->leave_type_code === 'SPECIAL' && $r->day_unit === 'HALF') {
                $reason = '半日特休。日次・勤務表の半日数と原本を確認';
            } elseif ($r->leave_type_code === 'PAID' && $r->request_category !== 'TIME_LEAVE') {
                $off = LeaveWorkdays::preview((int) $r->employee_id, $r->start_date, $r->end_date)['excludedDates'];
                if ($off) { $reason = '現在の勤務予定で公休 '.implode('、', $off).'。当時の勤務予定を原本で確認'; }
            }
            if ($reason !== null) {
                $this->line(sprintf('申請ID %d／職員ID %d／%s～%s／%s', $r->id, $r->employee_id, $r->start_date, $r->end_date, $reason));
                ++$count;
            }
        }
        $this->info("確認対象 {$count} 件");
        return self::SUCCESS;
    }
}
