<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AuditLogService
{
    public function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $targetType,
        ?string $targetId,
        array $detail = [],
        ?string $ipAddress = null,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        DB::table('audit_logs')->insert([
            'actor_type' => strtoupper(trim($actorType)),
            'actor_id' => $actorId,
            'action' => strtoupper(trim($action)),
            'target_type' => strtoupper(trim($targetType)),
            'target_id' => $targetId !== null ? trim($targetId) : null,
            'detail_json' => $detail !== [] ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'occurred_at' => ($occurredAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
            'ip_address' => $ipAddress ?? request()?->ip(),
        ]);
    }

    public function listForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('audit_logs as al')
            ->leftJoin('users as admin_users', function ($join) {
                $join->on('admin_users.id', '=', 'al.actor_id')
                    ->where('al.actor_type', '=', 'ADMIN');
            })
            ->leftJoin('employees as employee_actors', function ($join) {
                $join->on('employee_actors.id', '=', 'al.actor_id')
                    ->where('al.actor_type', '=', 'EMPLOYEE');
            })
            ->leftJoin('attendance_devices as device_actors', function ($join) {
                $join->on('device_actors.id', '=', 'al.actor_id')
                    ->where('al.actor_type', '=', 'DEVICE');
            })
            ->select([
                'al.id',
                'al.actor_type',
                'al.actor_id',
                'al.action',
                'al.target_type',
                'al.target_id',
                'al.detail_json',
                'al.occurred_at',
                'al.ip_address',
                'admin_users.name as admin_actor_name',
                'admin_users.email as admin_actor_email',
                'employee_actors.name as employee_actor_name',
                'employee_actors.employee_code as employee_actor_code',
                'device_actors.name as device_actor_name',
                'device_actors.device_code as device_actor_code',
            ]);

        if (!empty($filters['action'])) {
            $query->where('al.action', strtoupper((string) $filters['action']));
        }

        if (!empty($filters['from'])) {
            $query->whereDate('al.occurred_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('al.occurred_at', '<=', $filters['to']);
        }

        if (!empty($filters['actor'])) {
            $actor = trim((string) $filters['actor']);
            $query->where(function ($builder) use ($actor) {
                $builder
                    ->where('admin_users.name', 'like', '%' . $actor . '%')
                    ->orWhere('admin_users.email', 'like', '%' . $actor . '%')
                    ->orWhere('employee_actors.name', 'like', '%' . $actor . '%')
                    ->orWhere('employee_actors.employee_code', 'like', '%' . $actor . '%')
                    ->orWhere('device_actors.name', 'like', '%' . $actor . '%')
                    ->orWhere('device_actors.device_code', 'like', '%' . $actor . '%')
                    ->orWhere('al.actor_type', 'like', '%' . strtoupper($actor) . '%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('al.occurred_at')
            ->orderByDesc('al.id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapRow($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function mapRow(object $row): array
    {
        $detail = $this->decodeDetail($row->detail_json ?? null);
        $summary = $this->summarizeDetail($detail);

        return [
            'id' => (int) $row->id,
            'actorType' => (string) $row->actor_type,
            'actorId' => $row->actor_id !== null ? (int) $row->actor_id : null,
            'actorLabel' => $this->actorLabel($row),
            'action' => (string) $row->action,
            'targetType' => (string) $row->target_type,
            'targetId' => $row->target_id !== null ? (string) $row->target_id : null,
            'detail' => $summary,
            'payloadSummary' => $summary,
            'detailJson' => $detail,
            'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'ipAddress' => $row->ip_address !== null ? (string) $row->ip_address : null,
        ];
    }

    private function actorLabel(object $row): string
    {
        return match ((string) $row->actor_type) {
            'ADMIN' => $row->admin_actor_name
                ? sprintf('%s (管理者)', $row->admin_actor_name)
                : sprintf('管理者 #%s', $row->actor_id ?? '-'),
            'EMPLOYEE' => $row->employee_actor_name
                ? sprintf('%s%s', $row->employee_actor_name, $row->employee_actor_code ? sprintf(' (%s)', $row->employee_actor_code) : '')
                : sprintf('職員 #%s', $row->actor_id ?? '-'),
            'DEVICE' => $row->device_actor_name
                ? sprintf('%s%s', $row->device_actor_name, $row->device_actor_code ? sprintf(' (%s)', $row->device_actor_code) : '')
                : sprintf('端末 #%s', $row->actor_id ?? '-'),
            default => $row->actor_id !== null
                ? sprintf('%s #%s', $row->actor_type, $row->actor_id)
                : (string) $row->actor_type,
        };
    }

    private function decodeDetail(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function summarizeDetail(array $detail): string
    {
        if ($detail === []) {
            return '-';
        }

        $pairs = [];
        foreach ($this->flattenDetail($detail) as $key => $value) {
            $pairs[] = sprintf('%s: %s', $key, $value);
            if (count($pairs) >= 4) {
                break;
            }
        }

        return $pairs !== [] ? implode(' / ', $pairs) : '-';
    }

    /**
     * @return array<string, string>
     */
    private function flattenDetail(array $detail, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($detail as $key => $value) {
            $label = $prefix !== '' ? sprintf('%s.%s', $prefix, (string) $key) : (string) $key;

            if (is_array($value)) {
                $flattened += $this->flattenDetail($value, $label);
                continue;
            }

            if (is_bool($value)) {
                $flattened[$label] = $value ? 'true' : 'false';
                continue;
            }

            if ($value === null) {
                $flattened[$label] = 'null';
                continue;
            }

            $flattened[$label] = (string) $value;
        }

        return $flattened;
    }
}
