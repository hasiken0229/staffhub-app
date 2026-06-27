<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;

final class EnvironmentAdminController extends Controller
{
    public function index()
    {
        $checks = [
            $this->extensionCheck('fileinfo', 'CSV取込のファイル形式確認、給与明細PDF保存'),
            $this->extensionCheck('zip', '給与取込バッチZIP出力'),
            $this->extensionCheck('mbstring', 'Shift_JIS/UTF-8 CSV変換'),
            $this->extensionCheck('pdo', 'データベース接続'),
        ];

        $missing = array_values(array_filter($checks, fn (array $check) => !$check['enabled']));

        return ApiResponse::ok([
            'status' => $missing === [] ? 'OK' : 'MISSING_EXTENSION',
            'checks' => $checks,
            'missingCount' => count($missing),
            'message' => $missing === []
                ? '必要なPHP拡張は有効です。'
                : '一部のPHP拡張が無効です。取込やZIP/PDF出力が失敗する可能性があります。',
        ]);
    }

    private function extensionCheck(string $extension, string $purpose): array
    {
        return [
            'key' => $extension,
            'label' => 'PHP拡張 ' . $extension,
            'enabled' => extension_loaded($extension),
            'purpose' => $purpose,
        ];
    }
}
