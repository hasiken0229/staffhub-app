<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class PayrollDefinitionService
{
    public function __construct(private readonly PayrollTemplateCatalog $catalog)
    {
    }

    public function list(array $filters = []): array
    {
        $this->ensureDefaults();

        $query = DB::table('payroll_data_definitions');

        if (!empty($filters['statementType'])) {
            $query->where('statement_type', $this->catalog->normalizeStatementType((string) $filters['statementType']));
        }

        if (!empty($filters['activeOnly'])) {
            $query->where('is_active', 1);
        }

        return $query
            ->orderBy('statement_type')
            ->orderByDesc('is_active')
            ->orderByDesc('template_version')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row) => $this->mapDefinition($row))
            ->all();
    }

    public function save(array $payload, GenericUser $actor): array
    {
        $this->ensureDefaults();

        $statementType = $this->catalog->normalizeStatementType((string) $payload['statementType']);
        $definitionName = trim((string) ($payload['definitionName'] ?? ''));
        $isActive = array_key_exists('isActive', $payload)
            ? filter_var($payload['isActive'], FILTER_VALIDATE_BOOL)
            : true;

        if ($definitionName === '') {
            throw new ApiException('VALIDATION_ERROR', 'データ定義名を入力してください。', 422, [
                ['field' => 'definitionName', 'message' => 'データ定義名を入力してください。'],
            ]);
        }

        $definitionId = isset($payload['id']) ? (int) $payload['id'] : 0;
        $actorEmployeeId = $this->resolveActorEmployeeId($actor);

        return DB::transaction(function () use ($definitionId, $statementType, $definitionName, $isActive, $actorEmployeeId) {
            if ($definitionId > 0) {
                $existing = DB::table('payroll_data_definitions')->where('id', $definitionId)->first();
                if ($existing === null) {
                    throw new ApiException('NOT_FOUND', 'データ定義が見つかりません。', 404);
                }

                DB::table('payroll_data_definitions')
                    ->where('id', $definitionId)
                    ->update([
                        'definition_name' => $definitionName,
                        'is_active' => $isActive ? 1 : 0,
                        'updated_at' => now(),
                    ]);

                if ($isActive) {
                    $this->deactivateOtherDefinitions($statementType, $definitionId);
                }

                $row = DB::table('payroll_data_definitions')->where('id', $definitionId)->first();
                return $this->mapDefinition($row);
            }

            if ($isActive) {
                $this->deactivateOtherDefinitions($statementType, null);
            }

            $nextVersion = (int) DB::table('payroll_data_definitions')
                ->where('statement_type', $statementType)
                ->max('template_version');

            $id = (int) DB::table('payroll_data_definitions')->insertGetId([
                'statement_type' => $statementType,
                'definition_name' => $definitionName,
                'template_version' => max(1, $nextVersion + 1),
                'field_count' => $this->catalog->fieldCount($statementType),
                'sample_file_name' => $this->catalog->sampleFileName($statementType),
                'sample_headers_json' => json_encode($this->catalog->headers($statementType), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => $isActive ? 1 : 0,
                'created_by' => $actorEmployeeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('payroll_data_definitions')->where('id', $id)->first();
            return $this->mapDefinition($row);
        });
    }

    public function resolveDefinition(?int $definitionId, string $statementType): object
    {
        $this->ensureDefaults();

        $normalizedType = $this->catalog->normalizeStatementType($statementType);

        if ($definitionId !== null && $definitionId > 0) {
            $definition = DB::table('payroll_data_definitions')
                ->where('id', $definitionId)
                ->where('statement_type', $normalizedType)
                ->first();

            if ($definition === null) {
                throw new ApiException('NOT_FOUND', '対象のデータ定義が見つかりません。', 404);
            }

            return $definition;
        }

        $definition = DB::table('payroll_data_definitions')
            ->where('statement_type', $normalizedType)
            ->where('is_active', 1)
            ->orderByDesc('template_version')
            ->first();

        if ($definition === null) {
            throw new ApiException('NOT_FOUND', '有効なデータ定義が見つかりません。', 404);
        }

        return $definition;
    }

    public function downloadTemplate(string $statementType): array
    {
        $normalizedType = $this->catalog->normalizeStatementType($statementType);

        return [
            'fileName' => $this->catalog->sampleFileName($normalizedType),
            'csv' => $this->catalog->toCsvString($normalizedType),
            'contentType' => 'text/csv; charset=Shift_JIS',
        ];
    }

    private function ensureDefaults(): void
    {
        foreach (['PAYROLL', 'BONUS'] as $statementType) {
            $exists = DB::table('payroll_data_definitions')
                ->where('statement_type', $statementType)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('payroll_data_definitions')->insert([
                'statement_type' => $statementType,
                'definition_name' => $this->catalog->defaultDefinitionName($statementType),
                'template_version' => $this->catalog->templateVersion($statementType),
                'field_count' => $this->catalog->fieldCount($statementType),
                'sample_file_name' => $this->catalog->sampleFileName($statementType),
                'sample_headers_json' => json_encode($this->catalog->headers($statementType), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => 1,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function deactivateOtherDefinitions(string $statementType, ?int $keepId): void
    {
        $query = DB::table('payroll_data_definitions')
            ->where('statement_type', $statementType);

        if ($keepId !== null) {
            $query->where('id', '!=', $keepId);
        }

        $query->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);
    }

    private function mapDefinition(object $row): array
    {
        $headers = [];
        if (!empty($row->sample_headers_json)) {
            $headers = json_decode((string) $row->sample_headers_json, true) ?: [];
        }

        return [
            'id' => (int) $row->id,
            'statementType' => $row->statement_type,
            'statementTypeLabel' => $this->catalog->title((string) $row->statement_type),
            'definitionName' => $row->definition_name,
            'templateVersion' => (int) $row->template_version,
            'fieldCount' => (int) $row->field_count,
            'sampleFileName' => $row->sample_file_name,
            'sampleHeaders' => $headers,
            'isActive' => (bool) $row->is_active,
            'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ];
    }

    private function resolveActorEmployeeId(GenericUser $actor): ?int
    {
        if (strtoupper((string) $actor->role) === 'EMPLOYEE') {
            return (int) $actor->id;
        }

        $configured = (int) env('STAFFHUB_APPROVER_EMPLOYEE_ID', 0);
        if ($configured > 0 && DB::table('employees')->where('id', $configured)->exists()) {
            return $configured;
        }

        $firstActive = DB::table('employees')
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->value('id');

        return $firstActive ? (int) $firstActive : null;
    }
}
