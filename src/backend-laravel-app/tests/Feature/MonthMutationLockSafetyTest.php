<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Attendance\MonthMutationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class MonthMutationLockSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('savedStatuses')]
    public function test_locking_an_existing_month_preserves_every_saved_column(string $status): void
    {
        $this->savedMonth('2026-10', $status);
        $before = DB::table('attendance_monthly_closes')->first();

        $this->travelTo(now()->addYears(2));
        try {
            DB::transaction(function (): void {
                MonthMutationLock::acquire(['2026-10'], false);
                MonthMutationLock::acquire(['2026-10'], false);
            });
        } finally {
            $this->travelBack();
        }

        $this->assertEquals($before, DB::table('attendance_monthly_closes')->first());
        $this->assertDatabaseCount('attendance_monthly_closes', 1);
    }

    public static function savedStatuses(): array
    {
        return [['OPEN'], ['CLOSED']];
    }

    public function test_a_closed_month_still_rejects_writes_and_rolls_back_new_month_rows(): void
    {
        $this->savedMonth('2026-12', 'CLOSED');
        $before = DB::table('attendance_monthly_closes')->first();

        try {
            DB::transaction(fn () => MonthMutationLock::acquire(['2026-12', '2026-11']));
            $this->fail('A closed month must not be opened by acquiring its lock.');
        } catch (ApiException $exception) {
            $this->assertSame('CLOSED_PERIOD', $exception->errorCode);
        }

        $this->assertEquals($before, DB::table('attendance_monthly_closes')->first());
        $this->assertDatabaseCount('attendance_monthly_closes', 1);
        $this->assertDatabaseMissing('attendance_monthly_closes', ['target_year_month' => '2026-11']);
    }

    public function test_open_month_remains_writable_without_replacing_its_metadata(): void
    {
        $this->savedMonth('2026-10', 'OPEN');
        $before = DB::table('attendance_monthly_closes')->first();

        DB::transaction(fn () => MonthMutationLock::acquire(['2026-10']));

        $this->assertEquals($before, DB::table('attendance_monthly_closes')->first());
    }

    public function test_new_month_is_created_once_and_months_are_locked_in_normalized_order(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable('attendance_monthly_closes');
        $lockSql = DB::table('attendance_monthly_closes')->where('target_year_month', '2026-10')
            ->lockForUpdate()->limit(1)->toSql();
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertStringEndsWith(' for update', strtolower($lockSql));
        }
        DB::enableQueryLog();
        try {
            DB::transaction(fn () => MonthMutationLock::acquire(['2026-12', '2026-10', '2026-11', '2026-10']));
            $operations = [];
            foreach (DB::getQueryLog() as $query) {
                if (str_starts_with($query['query'], 'insert into '.$table.' ')) {
                    $monthBindings = array_values(array_filter($query['bindings'],
                        fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)));
                    $this->assertCount(1, $monthBindings);
                    $operations[] = ['upsert', $monthBindings[0]];
                } elseif ($query['query'] === $lockSql) {
                    $this->assertCount(1, $query['bindings']);
                    $operations[] = ['lock', $query['bindings'][0]];
                }
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([
            ['upsert', '2026-10'], ['lock', '2026-10'],
            ['upsert', '2026-11'], ['lock', '2026-11'],
            ['upsert', '2026-12'], ['lock', '2026-12'],
        ], $operations);
        $this->assertDatabaseCount('attendance_monthly_closes', 3);
        $first = DB::table('attendance_monthly_closes')->orderBy('target_year_month')->get()->toJson();
        DB::transaction(fn () => MonthMutationLock::acquire(['2026-11', '2026-12', '2026-10']));
        $this->assertSame($first, DB::table('attendance_monthly_closes')->orderBy('target_year_month')->get()->toJson());
        $this->assertSame(['OPEN'], DB::table('attendance_monthly_closes')->distinct()->pluck('status')->all());
    }

    public function test_new_lock_row_is_rolled_back_with_its_parent_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                MonthMutationLock::acquire(['2026-10']);
                throw new RuntimeException('synthetic transaction failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic transaction failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('attendance_monthly_closes', 0);
    }

    public function test_invalid_month_rolls_back_earlier_month_creation(): void
    {
        try {
            DB::transaction(fn () => MonthMutationLock::acquire(['2026-10', '2026-13']));
            $this->fail('An invalid month must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('VALIDATION_ERROR', $exception->errorCode);
        }
        $this->assertDatabaseCount('attendance_monthly_closes', 0);
    }

    private function savedMonth(string $month, string $status): void
    {
        $actor = DB::table('employees')->insertGetId([
            'employee_code' => 'LOCK-SYNTHETIC', 'name' => '合成職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendance_monthly_closes')->insert([
            'target_year_month' => $month, 'status' => $status,
            'closed_at' => '2026-10-01 12:34:56', 'closed_by' => $actor,
            'reopened_at' => '2026-10-02 09:08:07', 'reopened_by' => $actor,
            'note' => '保存済みの締め・再開履歴を保持',
            'created_at' => '2026-09-01 01:02:03', 'updated_at' => '2026-10-02 09:08:07',
        ]);
    }
}
