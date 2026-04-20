<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NoticeService
{
    public function listForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('notices as n')
            ->leftJoin('employees as e', 'e.id', '=', 'n.created_by')
            ->select([
                'n.id',
                'n.notice_type',
                'n.title',
                'n.body',
                'n.publish_start_at',
                'n.publish_end_at',
                'n.target_employee_id',
                'n.related_type',
                'n.related_id',
                'n.created_at',
                'e.name as created_by_name',
            ]);

        if (!empty($filters['noticeType'])) {
            $query->where('n.notice_type', strtoupper((string) $filters['noticeType']));
        }

        if (!empty($filters['activeOnly'])) {
            $query->where('n.publish_start_at', '<=', now())
                ->where(function ($builder) {
                    $builder->whereNull('n.publish_end_at')
                        ->orWhere('n.publish_end_at', '>=', now());
                });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('n.publish_start_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapAdminNotice($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function storeForAdmin(array $payload, GenericUser $actor): array
    {
        $creatorEmployeeId = $this->resolveActorEmployeeId($actor);
        $noticeType = strtoupper((string) $payload['noticeType']);
        $title = trim((string) $payload['title']);
        $body = trim((string) $payload['body']);

        $id = (int) DB::table('notices')->insertGetId([
            'notice_type' => $noticeType,
            'title' => $title,
            'body' => $body,
            'publish_start_at' => CarbonImmutable::parse($payload['publishStartAt'])->format('Y-m-d H:i:s'),
            'publish_end_at' => !empty($payload['publishEndAt'])
                ? CarbonImmutable::parse($payload['publishEndAt'])->format('Y-m-d H:i:s')
                : null,
            'target_employee_id' => $payload['targetEmployeeId'] ?? null,
            'related_type' => $payload['relatedType'] ?? null,
            'related_id' => $payload['relatedId'] ?? null,
            'created_by' => $creatorEmployeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notice = DB::table('notices as n')
            ->leftJoin('employees as e', 'e.id', '=', 'n.created_by')
            ->select([
                'n.id',
                'n.notice_type',
                'n.title',
                'n.body',
                'n.publish_start_at',
                'n.publish_end_at',
                'n.target_employee_id',
                'n.related_type',
                'n.related_id',
                'n.created_at',
                'e.name as created_by_name',
            ])
            ->where('n.id', $id)
            ->first();

        $this->addAudit(
            strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
            (int) $actor->id,
            'NOTICE_CREATED',
            'NOTICE',
            (string) $id,
            [
                'noticeType' => $noticeType,
                'title' => $title,
            ]
        );

        app(NotificationMailService::class)->sendNoticePublished(
            isset($payload['targetEmployeeId']) ? (int) $payload['targetEmployeeId'] : null,
            $noticeType,
            $title,
            $body,
            [
                '関連種別' => $payload['relatedType'] ?? null,
                '関連ID' => isset($payload['relatedId']) ? (string) $payload['relatedId'] : null,
            ]
        );

        return $this->mapAdminNotice($notice);
    }

    public function listForEmployee(int $employeeId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));
        $now = now()->format('Y-m-d H:i:s');

        $notices = DB::table('notices as n')
            ->leftJoin('notice_reads as nr', function ($join) use ($employeeId) {
                $join->on('nr.notice_id', '=', 'n.id')
                    ->where('nr.employee_id', '=', $employeeId);
            })
            ->select([
                DB::raw("'NOTICE' as source_type"),
                'n.id',
                'n.notice_type as notification_type',
                'n.title',
                'n.body',
                'n.related_type',
                'n.related_id',
                'n.publish_start_at as sent_at',
                'nr.read_at',
            ])
            ->where('n.publish_start_at', '<=', $now)
            ->where(function ($builder) use ($employeeId) {
                $builder->whereNull('n.target_employee_id')
                    ->orWhere('n.target_employee_id', $employeeId);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('n.publish_end_at')
                    ->orWhere('n.publish_end_at', '>=', $now);
            })
            ->get();

        $employeeNotifications = DB::table('notifications')
            ->select([
                DB::raw("'PERSONAL' as source_type"),
                'id',
                'notification_type',
                'title',
                'body',
                'related_type',
                'related_id',
                'sent_at',
                'read_at',
            ])
            ->where('employee_id', $employeeId)
            ->get();

        $items = $notices
            ->concat($employeeNotifications)
            ->sortByDesc(fn (object $row) => (string) $row->sent_at)
            ->values();

        $paged = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $paged->map(fn (object $row) => [
                'id' => (int) $row->id,
                'sourceType' => $row->source_type,
                'notificationType' => $row->notification_type,
                'title' => $row->title,
                'body' => $row->body,
                'relatedType' => $row->related_type,
                'relatedId' => $row->related_id !== null ? (int) $row->related_id : null,
                'sentAt' => CarbonImmutable::parse($row->sent_at)->toIso8601String(),
                'isRead' => $row->read_at !== null,
                'readAt' => $row->read_at ? CarbonImmutable::parse($row->read_at)->toIso8601String() : null,
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $items->count(),
            ],
        ];
    }

    public function unreadCountForEmployee(int $employeeId): int
    {
        $now = now()->format('Y-m-d H:i:s');

        $personal = (int) DB::table('notifications')
            ->where('employee_id', $employeeId)
            ->where('is_read', 0)
            ->count();

        $noticeUnread = (int) DB::table('notices as n')
            ->leftJoin('notice_reads as nr', function ($join) use ($employeeId) {
                $join->on('nr.notice_id', '=', 'n.id')
                    ->where('nr.employee_id', '=', $employeeId);
            })
            ->whereNull('nr.id')
            ->where(function ($builder) use ($employeeId) {
                $builder->whereNull('n.target_employee_id')
                    ->orWhere('n.target_employee_id', $employeeId);
            })
            ->where('n.publish_start_at', '<=', $now)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('n.publish_end_at')
                    ->orWhere('n.publish_end_at', '>=', $now);
            })
            ->count();

        return $personal + $noticeUnread;
    }

    public function markRead(int $employeeId, int $id, ?string $sourceType = null): array
    {
        $sourceType = strtoupper((string) ($sourceType ?? ''));
        $now = now()->format('Y-m-d H:i:s');
        $noticeExistsForEmployee = DB::table('notices')
            ->where('id', $id)
            ->where('publish_start_at', '<=', $now)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('publish_end_at')
                    ->orWhere('publish_end_at', '>=', $now);
            })
            ->where(function ($builder) use ($employeeId) {
                $builder->whereNull('target_employee_id')
                    ->orWhere('target_employee_id', $employeeId);
            })
            ->exists();

        if ($sourceType === 'NOTICE' || ($sourceType === '' && $noticeExistsForEmployee)) {
            DB::table('notice_reads')->updateOrInsert(
                [
                    'notice_id' => $id,
                    'employee_id' => $employeeId,
                ],
                [
                    'read_at' => now(),
                ]
            );

            return [
                'success' => true,
                'notificationId' => $id,
                'sourceType' => 'NOTICE',
            ];
        }

        $updated = DB::table('notifications')
            ->where('id', $id)
            ->where('employee_id', $employeeId)
            ->update([
                'is_read' => 1,
                'read_at' => now(),
            ]);

        if ($updated === 0) {
            throw new ApiException('NOT_FOUND', 'お知らせが見つかりません。', 404);
        }

        return [
            'success' => true,
            'notificationId' => $id,
            'sourceType' => 'PERSONAL',
        ];
    }

    public function createPayrollPublicationNotice(
        int $employeeId,
        string $statementType,
        string $targetYearMonth,
        int $statementId,
        ?int $creatorEmployeeId,
    ): void {
        $title = (strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細') . 'を公開しました';

        DB::table('notices')->insert([
            'notice_type' => strtoupper($statementType) === 'BONUS' ? 'BONUS_PUBLISHED' : 'PAYROLL_PUBLISHED',
            'title' => $title,
            'body' => $targetYearMonth . ' の' . (strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細') . 'を公開しました。',
            'publish_start_at' => now(),
            'publish_end_at' => null,
            'target_employee_id' => $employeeId,
            'related_type' => 'PAYROLL_STATEMENT',
            'related_id' => $statementId,
            'created_by' => $creatorEmployeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'employee_id' => $employeeId,
            'notification_type' => strtoupper($statementType) === 'BONUS' ? 'BONUS_PUBLISHED' : 'PAYROLL_PUBLISHED',
            'title' => $title,
            'body' => $targetYearMonth . ' の' . (strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細') . 'を確認できます。',
            'related_type' => 'PAYROLL_STATEMENT',
            'related_id' => $statementId,
            'is_read' => 0,
            'sent_at' => now(),
            'read_at' => null,
        ]);

        DB::afterCommit(function () use ($employeeId, $statementType, $targetYearMonth, $statementId): void {
            app(NotificationMailService::class)->sendPayrollPublished(
                $employeeId,
                $statementType,
                $targetYearMonth,
                $statementId,
            );
        });
    }

    private function mapAdminNotice(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'noticeType' => $row->notice_type,
            'title' => $row->title,
            'body' => $row->body,
            'publishStartAt' => CarbonImmutable::parse($row->publish_start_at)->toIso8601String(),
            'publishEndAt' => $row->publish_end_at ? CarbonImmutable::parse($row->publish_end_at)->toIso8601String() : null,
            'targetEmployeeId' => $row->target_employee_id !== null ? (int) $row->target_employee_id : null,
            'relatedType' => $row->related_type,
            'relatedId' => $row->related_id !== null ? (int) $row->related_id : null,
            'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'createdByName' => $row->created_by_name,
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

    private function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }
}
