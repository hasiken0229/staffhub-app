<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;

final class PaidLeaveRegisterReconciliation
{
    /** Only the authenticated administrator's selected employee is returned. */
    public function forEmployee(int $employeeId, string $employeeCode): array
    {
        $unknown = [
            'status' => 'NOT_ESTABLISHED', 'message' => 'この職員の移行残日数を照合できる記録がありません。',
            'asOf' => null, 'officialDays' => null, 'appDays' => null, 'sourceFile' => null, 'sourceRow' => null,
        ];
        $json = Storage::disk('local')->get('paid-leave-register/reconciliation.json');
        $data = is_string($json) ? json_decode($json, true) : null;
        $asOf = $data['asOf'] ?? null;
        $row = $data['employees'][$employeeCode] ?? null;
        if (($data['schemaVersion'] ?? null) !== 1 || !is_string($asOf) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $asOf)
            || !($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf)) || $parsed->format('Y-m-d') !== $asOf
            || !is_array($row) || ($row['employeeId'] ?? null) !== $employeeId
            || !is_numeric($row['appDays'] ?? null) || !is_numeric($row['officialDays'] ?? null)
            || !is_string($row['sourceFile'] ?? null) || !preg_match('/\A[\pL\pN_. -]+\.csv\z/u', $row['sourceFile'])
            || !is_int($row['sourceRow'] ?? null) || $row['sourceRow'] < 2) {
            return $unknown;
        }
        $app = (float) $row['appDays'];
        $official = (float) $row['officialDays'];
        $matched = abs($app - $official) < 0.000001;
        if (!is_finite($app) || !is_finite($official)
            || ($row['status'] ?? null) !== ($matched ? 'balance_matched' : 'needs_review')) {
            return $unknown;
        }

        return [
            'status' => $matched ? 'NO_OPEN_DIFFERENCE' : 'REVIEW_REQUIRED',
            'message' => $matched ? '照合日時点の日数残は一致しています。取得履歴と付与基準日は個別に確認してください。'
                : '照合日時点の公式残とアプリ残に差があります。確定には原本の有給管理簿を確認してください。',
            'asOf' => $asOf, 'officialDays' => $official, 'appDays' => $app,
            'sourceFile' => $row['sourceFile'], 'sourceRow' => $row['sourceRow'],
        ];
    }
}
