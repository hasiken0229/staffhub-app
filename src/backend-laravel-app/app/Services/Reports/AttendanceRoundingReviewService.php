<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only arithmetic comparison. Neither value is automatically adopted for payroll. */
final class AttendanceRoundingReviewService
{
    public function review(string $month, ?int $employeeId = null): array
    {
        [$monthStart,$monthEnd]=AttendancePeriod::forEmployee($month,'FULL_TIME');
        $from=$monthStart->subMonthNoOverflow()->day(21)->toDateString();
        $employees=DB::table('employees')->select(['id','employee_code','employment_type','joined_on','retired_on'])
            ->addSelect(Schema::hasColumn('employees','is_dependent_within') ? 'is_dependent_within' : DB::raw('0 as is_dependent_within'))
            ->addSelect(['legacy_calendar_month' => AttendancePeriod::legacyMonthFor($month, 'employees.id')])
            ->when($employeeId !== null,fn($q)=>$q->where('id',$employeeId))->orderBy('employee_code')->get();
        if ($employeeId !== null && $employees->isEmpty()) { throw new ApiException('NOT_FOUND','対象職員が見つかりません。',404); }
        $daily=DB::table('attendance_daily')->whereIn('employee_id',$employees->pluck('id')->all())
            ->whereBetween('target_date',[$from,$monthEnd->toDateString()])->orderBy('target_date')->get();
        $breaks=$daily->isEmpty() ? collect() : DB::table('attendance_daily_breaks')->whereIn('attendance_daily_id',$daily->pluck('id')->all())
            ->orderBy('segment_no')->get()->groupBy('attendance_daily_id');
        $grouped=$daily->groupBy('employee_id');$result=[];
        foreach ($employees as $employee) {
            [$start,$end]=AttendancePeriod::forEmployee($month,(string)$employee->employment_type,(bool)$employee->is_dependent_within, $employee->legacy_calendar_month ?? null);
            $start=$start->max(CarbonImmutable::parse($employee->joined_on));
            if ($employee->retired_on !== null) { $end=$end->min(CarbonImmutable::parse($employee->retired_on)); }
            if ($start->gt($end)) { continue; }
            $rows=[];$seconds=0;$storedComparable=0;$knownStored=0;$missing=0;$comparableCount=0;
            foreach ($grouped->get($employee->id,collect()) as $row) {
                if ($row->target_date < $start->toDateString() || $row->target_date > $end->toDateString()) { continue; }
                if ($row->work_minutes === null) { $missing++; } else { $knownStored+=(int)$row->work_minutes; }
                $item=$this->row($row,$breaks->get($row->id,collect())->all());
                if ($item['comparable']) { $seconds+=$item['secondsBeforeMinuteRounding'];$storedComparable+=(int)$row->work_minutes;$comparableCount++; }
                $rows[]=$item;
            }
            $result[]=['employeeId'=>(int)$employee->id,'employeeCode'=>$employee->employee_code,
                'periodStart'=>$start->toDateString(),'periodEnd'=>$end->toDateString(),'knownStoredMinutes'=>$knownStored,
                'missingStoredMinuteRows'=>$missing,'comparableStoredMinutes'=>$storedComparable,
                'comparableDayCount'=>$comparableCount,'comparableSeconds'=>$seconds,'monthlyFloorMinutes'=>$comparableCount>0 ? intdiv($seconds,60) : null,
                'differenceMinutes'=>$comparableCount>0 ? intdiv($seconds,60)-$storedComparable : null,'retainedRemainderSeconds'=>$comparableCount>0 ? $seconds-$storedComparable*60 : null,'rows'=>$rows];
        }
        return ['targetMonth'=>$month,'periodBasis'=>'PAYROLL','readOnly'=>true,'payrollValuesChanged'=>false,
            'roundingRuleChanged'=>false,'breaksObserved'=>false,'comparisonBasis'=>'採用出退勤の経過秒－保存済み休憩分×60。日別保存分と、比較可能行の秒合計を最後に分へ切り捨てた参考値を比較。',
            'note'=>'給与確定用ではありません。移行値・不完全行・保存分と計算が一致しない行は比較合計から除外します。休憩区間の秒も計算・保存値であり、実取得の証明ではありません。',
            'employees'=>$result];
    }

    private function row(object $row, array $breaks): array
    {
        $item=['dailyId'=>(int)$row->id,'targetDate'=>$row->target_date,'storedMinutes'=>$row->work_minutes !== null ? (int)$row->work_minutes : null,
            'comparable'=>false,'secondsBeforeMinuteRounding'=>null,'savedBreakSegmentSeconds'=>null,'segmentBasedNetSeconds'=>null,'reason'=>null];
        if (empty($row->clock_in_at) || empty($row->clock_out_at) || $row->work_minutes === null) { return [...$item,'reason'=>'INCOMPLETE']; }
        $start=CarbonImmutable::parse($row->clock_in_at);$end=CarbonImmutable::parse($row->clock_out_at);
        $gross=$end->getTimestamp()-$start->getTimestamp();$breakMinutes=(int)$row->break_minutes;
        if ($gross < 0 || $breakMinutes < 0 || $breakMinutes*60 > $gross) { return [...$item,'reason'=>'INVALID_DURATION']; }
        $net=$gross-$breakMinutes*60;
        $segmentSeconds=0;$segmentsValid=true;$previousEnd=null;
        foreach ($breaks as $break) {
            if (empty($break->break_start_at) || empty($break->break_end_at)) { $segmentsValid=false;break; }
            $a=CarbonImmutable::parse($break->break_start_at);$b=CarbonImmutable::parse($break->break_end_at);
            if ($b->lt($a) || $a->lt($start) || $b->gt($end) || ($previousEnd !== null && $a->lt($previousEnd))) { $segmentsValid=false;break; }
            $segmentSeconds+=$b->getTimestamp()-$a->getTimestamp();$previousEnd=$b;
        }
        if ($segmentsValid && ($breaks !== [] || $breakMinutes===0)) {
            $item['savedBreakSegmentSeconds']=$segmentSeconds;$item['segmentBasedNetSeconds']=$gross-$segmentSeconds;
        }
        if (!empty($row->source_system)) { return [...$item,'reason'=>'IMPORTED_VALUE_RETAINED']; }
        if ((int)$row->work_minutes !== intdiv($net,60)) { return [...$item,'reason'=>'STORED_VALUE_DIFFERS']; }
        return [...$item,'comparable'=>true,'secondsBeforeMinuteRounding'=>$net];
    }
}
