<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CardAssignmentService
{
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('employee_cards as c')
            ->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->select([
                'c.id',
                'c.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'c.card_uid',
                'c.is_active',
                'c.assigned_at',
                'c.revoked_at',
            ]);

        if (!empty($filters['cardUid'])) {
            $query->where('c.card_uid', 'like', '%' . strtoupper((string) $filters['cardUid']) . '%');
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . strtoupper((string) $filters['employeeCode']) . '%');
        }

        if (array_key_exists('isActive', $filters) && $filters['isActive'] !== null && $filters['isActive'] !== '') {
            $query->where('c.is_active', (int) filter_var($filters['isActive'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('c.assigned_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapCardRow($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function assign(int $employeeId, string $cardUid): array
    {
        return $this->assignWithActor($employeeId, $cardUid, 'ADMIN', $this->resolveActorId());
    }

    public function assignFromDevice(int $employeeId, string $cardUid, int $deviceId): array
    {
        return $this->assignWithActor($employeeId, $cardUid, 'DEVICE', $deviceId);
    }

    private function assignWithActor(int $employeeId, string $cardUid, string $actorType, ?int $actorId): array
    {
        $normalized = strtoupper(trim($cardUid));
        if ($normalized === '') {
            throw new ApiException('VALIDATION_ERROR', 'カードUIDを入力してください。', 400, [
                ['field' => 'cardUid', 'message' => 'カードUIDを入力してください。'],
            ]);
        }

        return DB::transaction(function () use ($employeeId, $normalized, $actorType, $actorId) {
            $employee = DB::table('employees')
                ->where('id', $employeeId)
                ->lockForUpdate()
                ->first();

            if ($employee === null) {
                throw new ApiException('EMPLOYEE_NOT_FOUND', '職員が見つかりません。', 404);
            }

            $existing = DB::table('employee_cards')
                ->where('employee_id', $employeeId)
                ->whereRaw('upper(card_uid) = ?', [$normalized])
                ->where('is_active', 1)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->mapCardRow((object) [
                    'id' => $existing->id,
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => $employee->name,
                    'card_uid' => $existing->card_uid,
                    'is_active' => $existing->is_active,
                    'assigned_at' => $existing->assigned_at,
                    'revoked_at' => $existing->revoked_at,
                ]);
            }

            DB::table('employee_cards')
                ->where('is_active', 1)
                ->where(function ($query) use ($employeeId, $normalized) {
                    $query->where('employee_id', $employeeId)
                        ->orWhereRaw('upper(card_uid) = ?', [$normalized]);
                })
                ->update([
                    'is_active' => 0,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $cardId = (int) DB::table('employee_cards')->insertGetId([
                'employee_id' => $employeeId,
                'card_uid' => $normalized,
                'is_active' => 1,
                'assigned_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->addAudit($actorType, $actorId, 'CARD_ASSIGNED', 'CARD', (string) $cardId, [
                'employeeId' => $employeeId,
                'employeeCode' => $employee->employee_code,
                'employeeName' => $employee->name,
                'cardUid' => $normalized,
            ]);

            $card = DB::table('employee_cards as c')
                ->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->select([
                    'c.id',
                    'c.employee_id',
                    'e.employee_code',
                    'e.name as employee_name',
                    'c.card_uid',
                    'c.is_active',
                    'c.assigned_at',
                    'c.revoked_at',
                ])
                ->where('c.id', $cardId)
                ->first();

            return $this->mapCardRow($card);
        });
    }

    public function revoke(int $cardId): bool
    {
        return DB::transaction(function () use ($cardId) {
            $card = DB::table('employee_cards as c')
                ->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->select([
                    'c.id',
                    'c.employee_id',
                    'e.employee_code',
                    'e.name as employee_name',
                    'c.card_uid',
                    'c.is_active',
                    'c.assigned_at',
                    'c.revoked_at',
                ])
                ->where('c.id', $cardId)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                return false;
            }

            DB::table('employee_cards')
                ->where('id', $cardId)
                ->update([
                    'is_active' => 0,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->addAudit('ADMIN', $this->resolveActorId(), 'CARD_REVOKED', 'CARD', (string) $cardId, [
                'employeeId' => (int) $card->employee_id,
                'employeeCode' => $card->employee_code,
                'employeeName' => $card->employee_name,
                'cardUid' => $card->card_uid,
            ]);

            return true;
        });
    }

    public function delete(int $cardId): bool
    {
        return DB::transaction(function () use ($cardId) {
            $card = DB::table('employee_cards as c')
                ->join('employees as e', 'e.id', '=', 'c.employee_id')
                ->select([
                    'c.id',
                    'c.employee_id',
                    'e.employee_code',
                    'e.name as employee_name',
                    'c.card_uid',
                    'c.is_active',
                ])
                ->where('c.id', $cardId)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                return false;
            }

            $this->addAudit('ADMIN', $this->resolveActorId(), 'CARD_DELETED', 'CARD', (string) $cardId, [
                'employeeId' => (int) $card->employee_id,
                'employeeCode' => $card->employee_code,
                'employeeName' => $card->employee_name,
                'cardUid' => $card->card_uid,
                'wasActive' => (bool) $card->is_active,
            ]);

            return DB::table('employee_cards')->where('id', $cardId)->delete() > 0;
        });
    }

    private function resolveActorId(): ?int
    {
        $user = request()?->user() ?? auth()->user();
        if ($user === null) {
            return 100;
        }

        return isset($user->id) ? (int) $user->id : 100;
    }

    private function addAudit(string $actorType, ?int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }

    private function mapCardRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'cardUid' => $row->card_uid,
            'isActive' => (bool) $row->is_active,
            'assignedAt' => CarbonImmutable::parse($row->assigned_at)->toIso8601String(),
            'revokedAt' => $row->revoked_at ? CarbonImmutable::parse($row->revoked_at)->toIso8601String() : null,
        ];
    }
}
