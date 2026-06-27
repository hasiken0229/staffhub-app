<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EmployeeAdminService
{
    public function index(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('employees as e')
            ->leftJoin('employee_auth as ea', 'ea.employee_id', '=', 'e.id')
            ->select([
                'e.*',
                'ea.login_id as login_email',
            ]);

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['name'])) {
            $query->where('e.name', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('e.status', $filters['status']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderBy('e.employee_code')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapEmployee($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function store(array $payload): array
    {
        $employeeCode = trim((string) $payload['employeeCode']);
        $googleChatUserId = $this->normalizeGoogleChatUserId($payload['googleChatUserId'] ?? null);
        $loginEmail = $this->normalizeLoginEmail($payload['loginEmail'] ?? null);

        if (DB::table('employees')->where('employee_code', $employeeCode)->exists()) {
            throw new ApiException('CONFLICT', '同じ社員番号の職員が既に存在します。', 409, [
                ['field' => 'employeeCode', 'message' => '同じ社員番号が既に存在します。'],
            ]);
        }

        $this->assertGoogleChatUserIdIsAvailable($googleChatUserId);
        $this->assertLoginEmailIsAvailable($loginEmail);

        $id = (int) DB::table('employees')->insertGetId([
            'employee_code' => $employeeCode,
            'name' => trim((string) $payload['name']),
            'kana' => $payload['kana'] ?? null,
            'department_name' => $payload['departmentName'] ?? null,
            'location_name' => $payload['locationName'] ?? null,
            'employment_type' => $payload['employmentType'],
            'status' => $payload['status'],
            'joined_on' => $payload['joinedOn'],
            'retired_on' => $payload['retiredOn'] ?? null,
            'google_chat_user_id' => $googleChatUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncLoginEmail($id, $loginEmail);

        return $this->mapEmployee($this->findEmployeeWithAuth($id));
    }

    public function update(int $id, array $payload): array
    {
        $employee = DB::table('employees')->where('id', $id)->first();
        if ($employee === null) {
            throw new ApiException('NOT_FOUND', '対象職員が見つかりません。', 404);
        }

        $employeeCode = trim((string) $payload['employeeCode']);
        $googleChatUserId = $this->normalizeGoogleChatUserId($payload['googleChatUserId'] ?? null);
        $loginEmail = $this->normalizeLoginEmail($payload['loginEmail'] ?? null);
        if (DB::table('employees')
            ->where('employee_code', $employeeCode)
            ->where('id', '<>', $id)
            ->exists()) {
            throw new ApiException('CONFLICT', '同じ社員番号の職員が既に存在します。', 409, [
                ['field' => 'employeeCode', 'message' => '同じ社員番号が既に存在します。'],
            ]);
        }

        $this->assertGoogleChatUserIdIsAvailable($googleChatUserId, $id);
        $this->assertLoginEmailIsAvailable($loginEmail, $id);

        DB::table('employees')
            ->where('id', $id)
            ->update([
                'employee_code' => $employeeCode,
                'name' => trim((string) $payload['name']),
                'kana' => $payload['kana'] ?? null,
                'department_name' => $payload['departmentName'] ?? null,
                'location_name' => $payload['locationName'] ?? null,
                'employment_type' => $payload['employmentType'],
                'status' => $payload['status'],
                'joined_on' => $payload['joinedOn'],
                'retired_on' => $payload['retiredOn'] ?? null,
                'google_chat_user_id' => $googleChatUserId,
                'updated_at' => now(),
            ]);

        $this->syncLoginEmail($id, $loginEmail);

        return $this->mapEmployee($this->findEmployeeWithAuth($id));
    }

    public function importFromCsv(array $payload, UploadedFile $file): array
    {
        $defaultDepartmentName = $payload['defaultDepartmentName'] ?? null;
        $defaultEmploymentType = $payload['defaultEmploymentType'] ?? 'FULL_TIME';
        $defaultStatus = $payload['defaultStatus'] ?? 'ACTIVE';
        $defaultJoinedOn = $payload['defaultJoinedOn'] ?? now()->toDateString();

        $rows = $this->readCsvRows($file);
        if (count($rows) < 2) {
            throw new ApiException('CSV_FORMAT_ERROR', 'CSVに職員データがありません。', 422);
        }

        $header = $rows[0];
        $map = $this->buildHeaderIndexMap($header);
        foreach (['社員番号', '姓', '名'] as $required) {
            if (!isset($map[$required])) {
                throw new ApiException('CSV_FORMAT_ERROR', 'CSVヘッダーが不足しています。', 422, [
                    ['field' => 'file', 'message' => '不足ヘッダー: ' . $required],
                ]);
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $items = [];

        foreach (array_slice($rows, 1) as $lineNumber => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $employeeCode = $this->cell($row, $map, '社員番号');
            $lastName = $this->cell($row, $map, '姓');
            $firstName = $this->cell($row, $map, '名');
            $name = trim($lastName . ' ' . $firstName);

            if ($employeeCode === '' || $name === '') {
                $skipped++;
                continue;
            }

            $existing = DB::table('employees')
                ->where('employee_code', $employeeCode)
                ->first();
            $hasKana = $this->hasHeader($map, 'ふりがな');
            $hasDepartment = $this->hasHeader($map, '所属');
            $hasLocation = $this->hasHeader($map, '勤務場所');
            $hasEmploymentType = $this->hasHeader($map, '雇用区分');
            $hasStatus = $this->hasHeader($map, '状態');
            $hasJoinedOn = $this->hasHeader($map, '入職日');
            $hasRetiredOn = $this->hasHeader($map, '退職日');
            $hasLoginEmail = $this->hasHeader($map, 'メールアドレス');

            $kana = $hasKana ? ($this->cell($row, $map, 'ふりがな') ?: null) : null;
            $departmentName = $hasDepartment ? ($this->cell($row, $map, '所属') ?: $defaultDepartmentName) : $defaultDepartmentName;
            $locationName = $hasLocation ? ($this->cell($row, $map, '勤務場所') ?: null) : null;
            $employmentType = $hasEmploymentType
                ? $this->normalizeEmploymentType($this->cell($row, $map, '雇用区分') ?: $defaultEmploymentType)
                : $defaultEmploymentType;
            $status = $hasStatus ? $this->normalizeStatus($this->cell($row, $map, '状態') ?: $defaultStatus) : $defaultStatus;
            $joinedOn = $hasJoinedOn ? ($this->cell($row, $map, '入職日') ?: $defaultJoinedOn) : $defaultJoinedOn;
            $retiredOn = $hasRetiredOn ? ($this->cell($row, $map, '退職日') ?: null) : null;
            $loginEmail = $hasLoginEmail ? $this->normalizeLoginEmail($this->cell($row, $map, 'メールアドレス')) : null;
            $googleChatUserId = $this->normalizeGoogleChatUserId($this->cell($row, $map, 'Google Chat ID'));

            if ($existing === null) {
                $this->assertGoogleChatUserIdIsAvailable($googleChatUserId);
                $this->assertLoginEmailIsAvailable($loginEmail);

                $id = (int) DB::table('employees')->insertGetId([
                    'employee_code' => $employeeCode,
                    'name' => $name,
                    'kana' => $kana,
                    'department_name' => $departmentName,
                    'location_name' => $locationName,
                    'employment_type' => $employmentType,
                    'status' => $status,
                    'joined_on' => $joinedOn,
                    'retired_on' => $retiredOn,
                    'google_chat_user_id' => $googleChatUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->syncLoginEmail($id, $loginEmail);
                $created++;
                $employee = $this->findEmployeeWithAuth($id);
                $action = 'CREATED';
            } else {
                $this->assertGoogleChatUserIdIsAvailable($googleChatUserId, (int) $existing->id);
                $this->assertLoginEmailIsAvailable($loginEmail, (int) $existing->id);

                $updates = [
                    'name' => $name,
                    'google_chat_user_id' => $googleChatUserId ?? $existing->google_chat_user_id,
                    'updated_at' => now(),
                ];

                if ($hasKana) {
                    $updates['kana'] = $kana;
                }
                if ($hasDepartment) {
                    $updates['department_name'] = $departmentName;
                }
                if ($hasLocation) {
                    $updates['location_name'] = $locationName;
                }
                if ($hasEmploymentType) {
                    $updates['employment_type'] = $employmentType;
                }
                if ($hasStatus) {
                    $updates['status'] = $status;
                }
                if ($hasJoinedOn) {
                    $updates['joined_on'] = $joinedOn;
                }
                if ($hasRetiredOn) {
                    $updates['retired_on'] = $retiredOn;
                }

                DB::table('employees')
                    ->where('id', $existing->id)
                    ->update($updates);
                if ($loginEmail !== null) {
                    $this->syncLoginEmail((int) $existing->id, $loginEmail);
                }
                $updated++;
                $employee = $this->findEmployeeWithAuth((int) $existing->id);
                $action = 'UPDATED';
            }

            $items[] = [
                'line' => $lineNumber + 2,
                'action' => $action,
                ...$this->mapEmployee($employee),
            ];
        }

        return [
            'processedCount' => count($items),
            'createdCount' => $created,
            'updatedCount' => $updated,
            'skippedCount' => $skipped,
            'items' => $items,
        ];
    }

    public function previewImportFromCsv(array $payload, UploadedFile $file): array
    {
        $defaultDepartmentName = $payload['defaultDepartmentName'] ?? null;
        $defaultEmploymentType = $payload['defaultEmploymentType'] ?? 'FULL_TIME';
        $defaultStatus = $payload['defaultStatus'] ?? 'ACTIVE';
        $defaultJoinedOn = $payload['defaultJoinedOn'] ?? now()->toDateString();

        $rows = $this->readCsvRows($file);
        if (count($rows) < 2) {
            throw new ApiException('CSV_FORMAT_ERROR', 'CSVに職員データがありません。', 422);
        }

        $header = $rows[0];
        $map = $this->buildHeaderIndexMap($header);
        foreach (['社員番号', '姓', '名'] as $required) {
            if (!isset($map[$required])) {
                throw new ApiException('CSV_FORMAT_ERROR', 'CSVヘッダーが不足しています。', 422, [
                    ['field' => 'file', 'message' => '不足ヘッダー: ' . $required],
                ]);
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $items = [];
        $errors = [];
        $seenEmployeeCodes = [];
        $seenLoginEmails = [];

        foreach (array_slice($rows, 1) as $lineNumber => $row) {
            $line = $lineNumber + 2;
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $employeeCode = $this->cell($row, $map, '社員番号');
            $lastName = $this->cell($row, $map, '姓');
            $firstName = $this->cell($row, $map, '名');
            $name = trim($lastName . ' ' . $firstName);
            $loginEmail = $this->hasHeader($map, 'メールアドレス') ? $this->normalizeLoginEmail($this->cell($row, $map, 'メールアドレス')) : null;
            $googleChatUserId = $this->normalizeGoogleChatUserId($this->cell($row, $map, 'Google Chat ID'));

            if ($employeeCode === '' || $name === '') {
                $skipped++;
                $errors[] = ['line' => $line, 'employeeCode' => $employeeCode ?: null, 'message' => '社員番号、姓、名は必須です。'];
                continue;
            }

            if (isset($seenEmployeeCodes[$employeeCode])) {
                $skipped++;
                $errors[] = ['line' => $line, 'employeeCode' => $employeeCode, 'message' => 'CSV内で職員番号が重複しています。'];
                continue;
            }
            $seenEmployeeCodes[$employeeCode] = true;

            if ($loginEmail !== null) {
                if (isset($seenLoginEmails[$loginEmail])) {
                    $skipped++;
                    $errors[] = ['line' => $line, 'employeeCode' => $employeeCode, 'message' => 'CSV内でメールアドレスが重複しています。'];
                    continue;
                }
                $seenLoginEmails[$loginEmail] = true;
            }

            $existing = DB::table('employees')->where('employee_code', $employeeCode)->first();
            $ignoreEmployeeId = $existing ? (int) $existing->id : null;

            try {
                $this->assertGoogleChatUserIdIsAvailable($googleChatUserId, $ignoreEmployeeId);
                $this->assertLoginEmailIsAvailable($loginEmail, $ignoreEmployeeId);
            } catch (ApiException $exception) {
                $skipped++;
                $errors[] = ['line' => $line, 'employeeCode' => $employeeCode, 'message' => $exception->getMessage()];
                continue;
            }

            $action = $existing === null ? 'CREATE' : 'UPDATE';
            $created += $action === 'CREATE' ? 1 : 0;
            $updated += $action === 'UPDATE' ? 1 : 0;

            $items[] = [
                'line' => $line,
                'action' => $action,
                'employeeCode' => $employeeCode,
                'name' => $name,
                'departmentName' => $this->hasHeader($map, '所属') ? ($this->cell($row, $map, '所属') ?: $defaultDepartmentName) : $defaultDepartmentName,
                'employmentType' => $this->hasHeader($map, '雇用区分')
                    ? $this->normalizeEmploymentType($this->cell($row, $map, '雇用区分') ?: $defaultEmploymentType)
                    : $defaultEmploymentType,
                'status' => $this->hasHeader($map, '状態') ? $this->normalizeStatus($this->cell($row, $map, '状態') ?: $defaultStatus) : $defaultStatus,
                'joinedOn' => $this->hasHeader($map, '入職日') ? ($this->cell($row, $map, '入職日') ?: $defaultJoinedOn) : $defaultJoinedOn,
                'loginEmail' => $loginEmail,
            ];
        }

        return [
            'dryRun' => true,
            'processedCount' => count($items) + $skipped,
            'createdCount' => $created,
            'updatedCount' => $updated,
            'skippedCount' => $skipped,
            'items' => $items,
            'errors' => $errors,
        ];
    }

    private function mapEmployee(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employeeCode' => $row->employee_code,
            'name' => $row->name,
            'kana' => $row->kana,
            'departmentName' => $row->department_name,
            'locationName' => $row->location_name ?? null,
            'employmentType' => $row->employment_type,
            'status' => $row->status,
            'joinedOn' => $row->joined_on,
            'retiredOn' => $row->retired_on,
            'loginEmail' => $row->login_email ?? null,
            'googleChatUserId' => $row->google_chat_user_id ?? null,
        ];
    }

    private function findEmployeeWithAuth(int $employeeId): object
    {
        return DB::table('employees as e')
            ->leftJoin('employee_auth as ea', 'ea.employee_id', '=', 'e.id')
            ->select(['e.*', 'ea.login_id as login_email'])
            ->where('e.id', $employeeId)
            ->first();
    }

    private function readCsvRows(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        $utf8Content = $this->convertCsvContentToUtf8($content);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        fwrite($handle, $utf8Content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map([$this, 'normalizeString'], $row);
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeString(?string $value): string
    {
        $value ??= '';
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        return trim($value);
    }

    private function buildHeaderIndexMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $columnName) {
            $map[$columnName] ??= [];
            $map[$columnName][] = $index;
        }

        return $map;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeString($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cell(array $row, array $map, string $header): string
    {
        $index = $map[$header][0] ?? null;
        if ($index === null) {
            return '';
        }

        return $this->normalizeString($row[$index] ?? '');
    }

    private function hasHeader(array $map, string $header): bool
    {
        return isset($map[$header]);
    }

    private function normalizeEmploymentType(string $value): string
    {
        return match ($value) {
            '常勤' => 'FULL_TIME',
            '非常勤' => 'PART_TIME',
            '契約' => 'CONTRACT',
            '臨時' => 'TEMPORARY',
            default => $value,
        };
    }

    private function normalizeStatus(string $value): string
    {
        return match ($value) {
            '在職' => 'ACTIVE',
            '停止' => 'INACTIVE',
            '退職' => 'RETIRED',
            default => $value,
        };
    }

    private function convertCsvContentToUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
    }

    private function normalizeGoogleChatUserId(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, 'users/')) {
            return $normalized;
        }

        return 'users/' . ltrim($normalized, '/');
    }

    private function normalizeLoginEmail(?string $value): ?string
    {
        $normalized = Str::lower(trim((string) $value));
        return $normalized === '' ? null : $normalized;
    }

    private function assertGoogleChatUserIdIsAvailable(?string $googleChatUserId, ?int $ignoreEmployeeId = null): void
    {
        if ($googleChatUserId === null) {
            return;
        }

        $query = DB::table('employees')->where('google_chat_user_id', $googleChatUserId);
        if ($ignoreEmployeeId !== null) {
            $query->where('id', '<>', $ignoreEmployeeId);
        }

        if ($query->exists()) {
            throw new ApiException('CONFLICT', '同じ Google Chat ID の職員が既に存在します。', 409, [
                ['field' => 'googleChatUserId', 'message' => '同じ Google Chat ID が既に存在します。'],
            ]);
        }
    }

    private function assertLoginEmailIsAvailable(?string $loginEmail, ?int $ignoreEmployeeId = null): void
    {
        if ($loginEmail === null) {
            return;
        }

        $query = DB::table('employee_auth')->where('login_id', $loginEmail);
        if ($ignoreEmployeeId !== null) {
            $query->where('employee_id', '<>', $ignoreEmployeeId);
        }

        if ($query->exists()) {
            throw new ApiException('CONFLICT', '同じメールアドレスの職員ログインが既に存在します。', 409, [
                ['field' => 'loginEmail', 'message' => '同じメールアドレスが既に存在します。'],
            ]);
        }
    }

    private function syncLoginEmail(int $employeeId, ?string $loginEmail): void
    {
        if ($loginEmail === null) {
            DB::table('employee_auth')->where('employee_id', $employeeId)->delete();
            return;
        }

        $existing = DB::table('employee_auth')->where('employee_id', $employeeId)->first();
        DB::table('employee_auth')->updateOrInsert(
            ['employee_id' => $employeeId],
            [
                'login_id' => $loginEmail,
                'password_hash' => $existing->password_hash ?? Hash::make(Str::random(40)),
                'password_updated_at' => $existing->password_updated_at ?? null,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ],
        );
    }
}
