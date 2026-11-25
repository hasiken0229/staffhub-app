<?php

namespace App\Services\Reports;

use App\Services\Leave\HourlyPaidLeaveService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PaidLeaveFiscalHistory
{
    private const EMPTY_BALANCE = ['days' => null, 'totalMinutes' => null, 'annualMinutes' => null];

    public function __construct(private readonly HourlyPaidLeaveService $hourly) {}

    public function forEmployee(
        int $employeeId, string $employeeCode, string $from, string $to, string $today,
        array $applicationEntries, array $ledgerBalances, ?string $firstGrant, ?string $incompleteFrom, array $warnings,
        bool $nativeGrantsOnly = false,
    ): array {
        $json = Storage::disk('local')->get('paid-leave-register/official-history.json');
        $document = is_string($json) ? json_decode($json, true) : null;
        $person = $document['employees'][$employeeCode] ?? null;
        if (($document['schemaVersion'] ?? null) !== 1 || !is_array($person) || ($person['employeeId'] ?? null) !== $employeeId) {
            $person = [];
        }
        $sources = is_array($document['sources'] ?? null) ? $document['sources'] : [];
        $appByYear = [];
        foreach ($applicationEntries as $row) {
            $appByYear[$this->year($row['date'])][] = $row;
        }
        $baselineDates = Schema::hasTable('paid_leave_hourly_balances')
            ? DB::table('paid_leave_hourly_balances')->where('employee_id', $employeeId)
                ->whereBetween('effective_on', [$from, min($to, $today)])->orderBy('effective_on')->pluck('effective_on')->all()
            : [];
        $hourlyCache = [];
        $hourlyAt = function (string $date) use (&$hourlyCache, $employeeId): ?array {
            if (!array_key_exists($date, $hourlyCache)) {
                $balance = $this->hourly->balance($employeeId, $date);
                $hourlyCache[$date] = $balance === null ? null : [
                    'effectiveOn' => $balance['effectiveOn'], 'annualMinutes' => $balance['remainingAsOfMinutes'],
                    'totalMinutes' => $balance['totalRemainingMinutes'] === null ? null : $balance['totalRemainingMinutes'] + $balance['futureApprovedMinutes'],
                ];
            }
            return $hourlyCache[$date];
        };
        $years = [];
        $firstYear = $this->year($from);
        $availableYears = array_keys(is_array($person['years'] ?? null) ? $person['years'] : []);
        $priorYears = array_filter($availableYears, static fn ($year) => is_int($year) && $year > 0 && $year < $firstYear);
        sort($priorYears);
        $boundary = null;
        $anchor = self::EMPTY_BALANCE;
        foreach (array_merge($priorYears, range($firstYear, $this->year($to))) as $year) {
            $start = sprintf('%04d-04-01', $year);
            $end = sprintf('%04d-03-31', $year + 1);
            $saved = is_array($person['years'][$year] ?? null) ? $person['years'][$year] : [];
            $opening = self::EMPTY_BALANCE;
            $openingRow = $saved['opening'] ?? null;
            $openingObserved = is_array($openingRow) && ($openingRow['date'] ?? null) === $start && $start <= $today
                && $this->observed($openingRow, $sources, $today);
            if ($openingObserved) {
                $opening = $this->balance($openingRow['days'] ?? null, $openingRow['totalMinutes'] ?? null, $openingRow['annualMinutes'] ?? null);
            }
            $covered = $saved['coveredThrough'] ?? null;
            $coveredFrom = $saved['coveredFrom'] ?? $start;
            $checkpointOnly = ($saved['coverageKind'] ?? null) === 'CHECKPOINT_ONLY';
            $coverageSource = is_string($saved['sourceId'] ?? null) ? ($sources[$saved['sourceId']] ?? false) : null;
            $raw = is_array($saved['entries'] ?? null) ? $saved['entries'] : [];
            if (!$this->date($covered) || !$this->date($coveredFrom) || $coveredFrom < $start || $covered < $coveredFrom
                || $covered > min($today, $end) || !$this->coverageVerified($coverageSource, $openingRow, $raw, $sources, $covered, $today)) {
                $covered = null;
                $coveredFrom = null;
            }

            $official = $this->officialRows($raw, $sources, $start, $end, $today,
                $openingObserved ? $openingRow : null);
            if ($covered !== null) {
                $boundary = $covered;
                $anchor = $this->officialBalanceAt($covered, $opening, $official);
            }
            if ($year < $firstYear) { continue; }
            $appAtBoundary = $boundary === null ? null : $this->ledgerAt($boundary, $ledgerBalances, $firstGrant, $incompleteFrom);
            $compatible = $boundary === null ? $nativeGrantsOnly :
                ($anchor['days'] !== null && $appAtBoundary !== null && abs($anchor['days'] - $appAtBoundary) < 0.000001);
            $native = function (string $date) use ($hourlyAt, $start, $boundary, $anchor, $compatible, $firstGrant, $ledgerBalances, $incompleteFrom, $warnings): array {
                $result = self::EMPTY_BALANCE;
                if ($boundary !== null && $date < $boundary) { return $result; }
                if ($compatible) {
                    $result['days'] = $this->ledgerAt($date, $ledgerBalances, $firstGrant, $incompleteFrom);
                    foreach ($warnings as $warning) {
                        if (($boundary === null || $warning['date'] > $boundary) && $warning['date'] <= $date) {
                            $result['days'] = null;
                            break;
                        }
                    }
                }
                $current = $hourlyAt($date);
                if ($current === null) { return $result; }
                $newBaseline = $boundary !== null ? $current['effectiveOn'] > $boundary : $current['effectiveOn'] >= $start;
                $baseline = !$newBaseline && $boundary !== null ? $hourlyAt($boundary) : null;
                foreach (['annualMinutes', 'totalMinutes'] as $field) {
                    if ($newBaseline || ($baseline !== null && $current['effectiveOn'] === $baseline['effectiveOn']
                        && $anchor[$field] !== null && $anchor[$field] === $baseline[$field])) {
                        $result[$field] = $current[$field];
                    }
                }
                return $result;
            };
            $entries = array_values(array_filter($official, static fn ($r) => $r['date'] >= $from && $r['date'] <= $to));
            $application = $appByYear[$year] ?? [];
            $this->appendApplication($entries, $application, $coveredFrom, $covered, $checkpointOnly, $native,
                array_filter($baselineDates, fn ($d) => $d >= $start && $d <= $end && ($covered === null || $d > $covered)));
            usort($entries, static fn ($a, $b) => strcmp($a['date'], $b['date'])
                ?: ($checkpointOnly ? (($a['kind'] === 'BALANCE') <=> ($b['kind'] === 'BALANCE')) : 0));
            $closingDate = max($from, $start) <= $today ? min($today, $end, $to) : null;
            $closing = self::EMPTY_BALANCE;
            if ($closingDate !== null) {
                $dated = $closingDate === $start && $openingObserved;
                foreach ($official as $row) {
                    if ($row['date'] === $closingDate && $row['status'] === 'RECORDED') { $dated = true; break; }
                }
                if ($dated || ($covered !== null && $closingDate >= $coveredFrom && $closingDate <= $covered)) {
                    $closing = $this->officialBalanceAt($closingDate, $opening, $official);
                } elseif ($covered === null || $closingDate > $covered) {
                    $closing = $native($closingDate);
                }
            }
            $fields = count(array_filter($opening, static fn ($v) => $v !== null));
            $beforeEmployment = !$fields && ($saved['openingReason'] ?? null) === 'NOT_EMPLOYED' && $covered !== null;
            $years[] = ['fiscalYear' => $year, 'openingDate' => $start, 'opening' => $opening,
                'openingReason' => $beforeEmployment ? 'NOT_EMPLOYED' : null,
                'closingDate' => $closingDate, 'closing' => $closing,
                'coveredThrough' => $covered, 'entries' => $entries,
                'status' => $fields === 3 ? 'AVAILABLE' : ($fields || $official || $application ? 'PARTIAL' : 'NOT_ESTABLISHED'),
                'message' => $beforeEmployment ? '4月1日時点は入職前です。' :
                    ($fields ? '4月1日の調整後残。空欄の残数は未確認です。' : '4月1日の調整後残は未確認です。')];
        }
        return ['years' => $years];
    }

    private function officialBalanceAt(string $date, array $opening, array $official): array
    {
        $balance = $opening;
        foreach ($official as $row) {
            if ($row['date'] > $date) { break; }
            if ($row['status'] === 'RECORDED' && !$row['includedInOpening']) {
                $balance = ['days'=>$row['daysAfter'], 'totalMinutes'=>$row['totalMinutesAfter'], 'annualMinutes'=>$row['annualMinutesAfter']];
            }
        }
        return $balance;
    }

    private function ledgerAt(string $date, array $ledgerBalances, ?string $firstGrant, ?string $incompleteFrom): ?float
    {
        if ($firstGrant === null || $firstGrant > $date || ($incompleteFrom !== null && $date >= $incompleteFrom)) { return null; }
        $value = null;
        foreach ($ledgerBalances as $on => $amount) {
            if ($on > $date) { break; }
            $value = $amount;
        }
        return $value;
    }

    private function officialRows(array $raw, array $sources, string $start, string $end, string $today, ?array $opening): array
    {
        $rows = $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !$this->date($item['date'] ?? null) || $item['date'] < $start || $item['date'] > $end
                || !$this->hasSource($item, $sources) || $sources[$item['sourceId']]['asOf'] > $today) { continue; }
            // A single source row may contain both a day and an hour amount.
            $key = $item['sourceId'].'|'.$item['sourceRow'];
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $date = $item['date'];
            $confirmed = $date <= $today && $this->observed($item, $sources, $today) && ($item['status'] ?? 'RECORDED') === 'RECORDED';
            // A row that was still only a forecast when the file was issued cannot certify a later actual.
            if ($date <= $today && !$confirmed) { continue; }
            $days = $this->number($item['days'] ?? null);
            $minutes = $this->minutes($item['minutes'] ?? null);
            $kind = $item['kind'] ?? (($days > 0 || $minutes > 0) ? 'USE' : 'BALANCE');
            if (!in_array($kind, ['USE','GRANT','CARRY','ADJUSTMENT','EXPIRE','BALANCE'], true)) { $kind = 'BALANCE'; }
            $use = $days > 0 || $minutes > 0;
            $included = $date === $start && $opening !== null;
            if ($included && !$use) { continue; }
            // The opening is AFTER all April 1 rows. Printing an earlier intraday balance below it would reverse their order.
            $showBalance = $confirmed && (!$included || ($opening['sourceId'] === $item['sourceId'] && $opening['sourceRow'] === $item['sourceRow']));
            $rows[] = [
                'date'=>$date, 'kind'=>$use ? 'USE' : $kind, 'status'=>$confirmed ? 'RECORDED' : 'FUTURE',
                'includedInOpening'=>$included,
                'usedDays'=>$days !== null && $days >= 0 ? $days : null, 'usedMinutes'=>$minutes !== null && $minutes >= 0 ? $minutes : null,
                'dayChange'=>$this->number($item['dayChange'] ?? null), 'minutesChange'=>$this->minutes($item['minutesChange'] ?? null),
                'daysAfter'=>$showBalance ? $this->number($item['afterDays'] ?? null) : null,
                'totalMinutesAfter'=>$showBalance ? $this->minutes($item['afterTotalMinutes'] ?? null) : null,
                'annualMinutesAfter'=>$showBalance ? $this->minutes($item['afterAnnualMinutes'] ?? null) : null,
            ];
        }
        // Stable sorting retains the source sequence for multiple changes on the same day.
        usort($rows, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $rows;
    }

    private function appendApplication(array &$entries, array $application, ?string $coveredFrom, ?string $covered,
        bool $checkpointOnly, callable $native, array $baselineDates): void
    {
        $sourceUse = [];
        $sourceDays = [];
        $lastRecorded = [];
        foreach ($entries as $row) {
            $date = $row['date'];
            $sourceDays[$date] = true;
            foreach (['usedDays','usedMinutes'] as $unit) {
                $sourceUse[$date][$unit] = ($sourceUse[$date][$unit] ?? 0) + ($row[$unit] ?? 0);
            }
        }
        // Match confirmed application requests before matching residual reference-only attendance marks.
        usort($application, static fn ($a, $b) => ($a['status'] === 'REFERENCE') <=> ($b['status'] === 'REFERENCE'));
        foreach ($application as $app) {
            $date = $app['date'];
            if (!in_array($app['kind'], ['USE','ATTENDANCE_CANDIDATE','GRANT','ADJUST_PLUS','ADJUST_MINUS','EXPIRE'], true)) { continue; }
            $isUse = $app['kind'] === 'USE' || $app['kind'] === 'ATTENDANCE_CANDIDATE';
            $days = $minutes = null;
            $status = $app['status'];
            $overlap = !$checkpointOnly && (($covered !== null && $date >= $coveredFrom && $date <= $covered) || isset($sourceDays[$date]));
            if ($isUse) {
                $unit = $app['unit'] === 'HOUR' ? 'usedMinutes' : 'usedDays';
                $quantity = (float) ($unit === 'usedMinutes' ? $app['minutes'] : $app['days']);
                $matched = min($quantity, $sourceUse[$date][$unit] ?? 0);
                $sourceUse[$date][$unit] = ($sourceUse[$date][$unit] ?? 0) - $matched;
                $quantity -= $matched;
                if ($quantity < 0.000001) { continue; }
                if ($overlap && $status !== 'FUTURE') { $status = 'REFERENCE'; }
                if ($unit === 'usedMinutes') { $minutes = (int) $quantity; } else { $days = $quantity; }
            } elseif ($overlap) {
                // General adjustments/expiry cannot safely identify a HRMOS movement by date alone.
                continue;
            }
            $entries[] = ['date'=>$date, 'kind'=>$isUse ? 'USE' : (str_starts_with($app['kind'], 'ADJUST_') ? 'ADJUSTMENT' : $app['kind']),
                'status'=>$status,'usedDays'=>$days,'usedMinutes'=>$minutes,'includedInOpening'=>false,
                'dayChange'=>$status === 'RECORDED' ? $app['deltaDays'] : null, 'minutesChange'=>null,
                'daysAfter'=>null,'annualMinutesAfter'=>null,'totalMinutesAfter'=>null];
            if ($status === 'RECORDED' && ($covered === null || $date > $covered)) {
                $lastRecorded[$date] = array_key_last($entries);
            }
        }
        foreach ($baselineDates as $date) {
            if (!isset($lastRecorded[$date])) {
                $entries[] = ['date'=>$date, 'kind'=>'BALANCE', 'status'=>'RECORDED', 'usedDays'=>null, 'usedMinutes'=>null,
                    'includedInOpening'=>false, 'dayChange'=>null, 'minutesChange'=>null,
                    'daysAfter'=>null, 'annualMinutesAfter'=>null, 'totalMinutesAfter'=>null];
                $lastRecorded[$date] = array_key_last($entries);
            }
        }
        // Native ledgers give an end-of-day value, not the result between two same-day approvals.
        foreach ($lastRecorded as $date => $index) {
            $endOfDay = $native($date);
            $entries[$index]['daysAfter'] = $endOfDay['days'];
            $entries[$index]['annualMinutesAfter'] = $endOfDay['annualMinutes'];
            $entries[$index]['totalMinutesAfter'] = $endOfDay['totalMinutes'];
        }
    }

    private function year(string $date): int
    {
        return (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
    }

    private function balance(mixed $days, mixed $total, mixed $annual): array
    {
        return ['days'=>$this->number($days),'totalMinutes'=>$this->minutes($total),'annualMinutes'=>$this->minutes($annual)];
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    private function minutes(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function date(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) { return false; }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function hasSource(array $item, array $sources): bool
    {
        $id = $item['sourceId'] ?? null;
        if (!is_string($id) || !is_int($item['sourceRow'] ?? null) || $item['sourceRow'] < 1 || !is_array($sources[$id] ?? null)) {
            return false;
        }
        return $this->validSource($sources[$id]);
    }

    private function validSource(array $source): bool
    {
        return is_string($source['sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/i', $source['sha256'])
            && $this->date($source['asOf'] ?? null);
    }

    private function coverageVerified(mixed $coverageSource, mixed $opening, array $rows, array $sources, string $covered, string $today): bool
    {
        if ($coverageSource !== null) {
            return is_array($coverageSource) && $this->validSource($coverageSource)
                && $coverageSource['asOf'] >= $covered && $coverageSource['asOf'] <= $today;
        }
        // Also accept row-level source references on manifests with no separate coverage source.
        if (is_array($opening)) { $rows[] = $opening; }
        foreach ($rows as $row) {
            if (is_array($row) && $this->date($row['date'] ?? null) && $row['date'] <= $covered
                && $this->observed($row, $sources, $today) && $sources[$row['sourceId']]['asOf'] >= $covered) {
                return true;
            }
        }
        return false;
    }

    private function observed(array $item, array $sources, string $today): bool
    {
        return $this->hasSource($item, $sources) && $item['date'] <= $sources[$item['sourceId']]['asOf']
            && $sources[$item['sourceId']]['asOf'] <= $today;
    }
}
