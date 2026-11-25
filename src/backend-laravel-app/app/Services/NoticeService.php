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
                'n.updated_at', 'n.revision', 'n.stopped_at', 'n.notification_sent_at', 'n.notification_queued_at',
                'e.name as created_by_name',
            ]);

        if (!empty($filters['noticeType'])) {
            $query->where('n.notice_type', strtoupper((string) $filters['noticeType']));
        }

        if (!empty($filters['activeOnly'])) {
            $query->whereNull('n.stopped_at')->where('n.publish_start_at', '<=', now())
                ->where(function ($builder) {
                    $builder->whereNull('n.publish_end_at')
                        ->orWhere('n.publish_end_at', '>', now());
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
        $normalized = $this->normalizeNoticePayload($payload);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $requestId = $payload['requestId'] ?? null;
        try {
            return DB::transaction(function () use ($payload, $normalized, $fingerprint, $requestId, $actor) {
                if ($requestId !== null) {
                    $existing = DB::table('notices')->where('request_id', $requestId)->lockForUpdate()->first();
                    if ($existing !== null) return $this->replayNotice($existing, $fingerprint);
                }
                $id = (int) DB::table('notices')->insertGetId([
                    ...$normalized, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                    'created_by' => $this->resolveActorEmployeeId($actor), 'notification_queued_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->addAudit(strtoupper((string) $actor->role), (int) $actor->id, 'NOTICE_CREATED', 'NOTICE', (string) $id,
                    ['title' => $normalized['title'], 'noticeType' => $normalized['notice_type'], 'publishStartAt' => $normalized['publish_start_at']]);
                $this->queueNoticePublication($id, $normalized['publish_start_at']);
                return $this->detailForAdmin($id);
            });
        } catch (\Illuminate\Database\QueryException $error) {
            $existing = $requestId === null ? null : DB::table('notices')->where('request_id', $requestId)->first();
            if ($existing !== null) return $this->replayNotice($existing, $fingerprint);
            throw $error;
        }
    }

    private function replayNotice(object $existing, string $fingerprint): array
    {
        if (!hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new ApiException('REQUEST_ID_CONFLICT', '同じ処理IDで内容が変更されています。新しい登録としてやり直してください。', 409);
        }
        return [...$this->detailForAdmin((int) $existing->id), 'unchanged' => true];
    }

    private function normalizeNoticePayload(array $payload): array
    {
        $start = CarbonImmutable::parse($payload['publishStartAt'])->setTimezone(config('app.timezone'));
        $end = empty($payload['publishEndAt']) ? null : CarbonImmutable::parse($payload['publishEndAt'])->setTimezone(config('app.timezone'));
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw new ApiException('VALIDATION_ERROR', '公開終了は公開開始より後にしてください。', 422,
                [['field' => 'publishEndAt', 'message' => '公開終了は公開開始より後にしてください。']]);
        }
        if (trim((string) $payload['title']) === '' || trim((string) $payload['body']) === '') {
            throw new ApiException('VALIDATION_ERROR', '件名と本文を入力してください。', 422);
        }
        return [
            'notice_type' => strtoupper((string) $payload['noticeType']), 'title' => trim((string) $payload['title']),
            'body' => trim((string) $payload['body']), 'publish_start_at' => $start->format('Y-m-d H:i:s'),
            'publish_end_at' => $end?->format('Y-m-d H:i:s'), 'target_employee_id' => $payload['targetEmployeeId'] ?? null,
            'related_type' => $payload['relatedType'] ?? null, 'related_id' => $payload['relatedId'] ?? null,
        ];
    }

    public function detailForAdmin(int $id): array
    {
        $notice = DB::table('notices as n')->leftJoin('employees as e', 'e.id', '=', 'n.created_by')
            ->select('n.*', 'e.name as created_by_name')->where('n.id', $id)->first();
        if ($notice === null) throw new ApiException('NOT_FOUND', 'お知らせが見つかりません。', 404);
        return $this->mapAdminNotice($notice);
    }

    public function updateForAdmin(int $id, array $payload, GenericUser $actor): array
    {
        return DB::transaction(function () use ($id, $payload, $actor) {
            $notice = DB::table('notices')->where('id', $id)->lockForUpdate()->first();
            $this->assertEditableNotice($notice);
            if ((int) $notice->revision !== (int) $payload['revision']) {
                throw new ApiException('NOTICE_CHANGED', '別の操作でお知らせが更新されています。詳細を開き直してください。', 409);
            }
            if ($notice->stopped_at !== null) throw new ApiException('NOTICE_STOPPED', '公開停止したお知らせは編集できません。', 409);
            $attributes = $this->normalizeNoticePayload([...$payload,
                'targetEmployeeId' => $notice->target_employee_id, 'relatedType' => $notice->related_type, 'relatedId' => $notice->related_id]);
            DB::table('notices')->where('id', $id)->update([...$attributes, 'revision' => $notice->revision + 1, 'updated_at' => now()]);
            $this->addAudit(strtoupper((string) $actor->role), (int) $actor->id, 'NOTICE_UPDATED', 'NOTICE', (string) $id,
                ['before' => ['title' => $notice->title, 'body' => $notice->body, 'publishStartAt' => $notice->publish_start_at,
                    'publishEndAt' => $notice->publish_end_at, 'revision' => (int) $notice->revision], 'after' => $attributes]);
            // Existing queued publications use the edited content. Already notified or legacy notices are never resent by editing.
            if ($notice->notification_sent_at === null && $notice->notification_queued_at !== null
                && $attributes['publish_start_at'] !== $notice->publish_start_at) {
                $this->queueNoticePublication($id, $attributes['publish_start_at']);
            }
            return $this->detailForAdmin($id);
        });
    }

    public function stopForAdmin(int $id, int $revision, GenericUser $actor): array
    {
        return DB::transaction(function () use ($id, $revision, $actor) {
            $notice = DB::table('notices')->where('id', $id)->lockForUpdate()->first();
            $this->assertEditableNotice($notice);
            if ($notice->stopped_at !== null) return [...$this->detailForAdmin($id), 'unchanged' => true];
            if ((int) $notice->revision !== $revision) throw new ApiException('NOTICE_CHANGED', '別の操作でお知らせが更新されています。詳細を開き直してください。', 409);
            DB::table('notices')->where('id', $id)->update(['stopped_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->addAudit(strtoupper((string) $actor->role), (int) $actor->id, 'NOTICE_STOPPED', 'NOTICE', (string) $id,
                ['title' => $notice->title, 'revision' => $revision + 1]);
            return $this->detailForAdmin($id);
        });
    }

    private function assertEditableNotice(?object $notice): void
    {
        if ($notice === null) throw new ApiException('NOT_FOUND', 'お知らせが見つかりません。', 404);
        if (in_array(strtoupper((string) $notice->notice_type), ['PAYROLL_PUBLISHED', 'BONUS_PUBLISHED'], true)
            || $notice->related_type === 'PAYROLL_STATEMENT') {
            throw new ApiException('NOTICE_AUTOMATIC_READ_ONLY', '給与・賞与の自動通知は給与明細から管理してください。', 409);
        }
    }

    private function queueNoticePublication(int $id, string $publishedAt): void
    {
        $job = new \App\Jobs\SendNoticePublication($id);
        app('queue')->connection('database')->later(CarbonImmutable::parse($publishedAt), $job, '', 'staffhub-notifications');
        $this->addAudit('SYSTEM', 0, 'NOTICE_NOTIFICATION_QUEUED', 'NOTICE_NOTIFICATION', $job->eventId(), ['noticeId' => $id, 'publishedAt' => $publishedAt]);
    }


    public function listForEmployee(int $employeeId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));
        $items = $this->visibleEmployeeNotices($employeeId);
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

    private function visibleEmployeeNotices(int $employeeId): \Illuminate\Support\Collection
    {
        $now = now()->format('Y-m-d H:i:s');

        $notices = DB::table('notices as n')
            ->whereNull('n.stopped_at')
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
                    ->orWhere('n.publish_end_at', '>', $now);
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
            ->where('employee_id', $employeeId)->where('sent_at', '<=', $now)
            ->get();

        $statements = DB::table('payroll_statements')->where('employee_id', $employeeId)
            ->whereNull('deleted_at')->where('published_at', '<=', $now)->pluck('id')->all();
        $items = $notices->concat($employeeNotifications)->filter(function ($row) use ($statements) {
            return $row->related_type !== 'PAYROLL_STATEMENT' || in_array((int) $row->related_id, array_map('intval', $statements), true);
        })->groupBy(function ($row) {
            return $row->related_type === 'PAYROLL_STATEMENT'
                ? 'payroll:'.$row->related_id.':'.$row->notification_type
                : $row->source_type.':'.$row->id;
        })->map(function ($rows) {
            $row = clone ($rows->firstWhere('source_type', 'NOTICE') ?? $rows->first());
            $row->read_at = $rows->pluck('read_at')->filter()->sortDesc()->first();
            return $row;
        })->sortByDesc(fn (object $row) => (string) $row->sent_at)->values();

        return $items;
    }

    public function unreadCountForEmployee(int $employeeId): int
    {
        return $this->visibleEmployeeNotices($employeeId)->filter(fn ($row) => $row->read_at === null)->count();
    }

    public function markRead(int $employeeId, int $id, ?string $sourceType = null): array
    {
        $sourceType = strtoupper((string) ($sourceType ?? ''));
        $now = now()->format('Y-m-d H:i:s');
        $noticeExistsForEmployee = DB::table('notices')
            ->whereNull('stopped_at')
            ->where('id', $id)
            ->where('publish_start_at', '<=', $now)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('publish_end_at')
                    ->orWhere('publish_end_at', '>', $now);
            })
            ->where(function ($builder) use ($employeeId) {
                $builder->whereNull('target_employee_id')
                    ->orWhere('target_employee_id', $employeeId);
            })
            ->exists();

        if ($sourceType === 'NOTICE' && !$noticeExistsForEmployee) {
            throw new ApiException('NOT_FOUND', 'お知らせが見つかりません。', 404);
        }
        if (($sourceType === 'NOTICE' || $sourceType === '') && $noticeExistsForEmployee) {
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
            ->where('sent_at', '<=', $now)
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
        ?CarbonImmutable $publishedAt = null,
        ?string $version = null,
    ): void {
        $publishedAt ??= CarbonImmutable::now();
        $title = (strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細') . 'を公開しました';

        DB::table('notices')->insert([
            'notice_type' => strtoupper($statementType) === 'BONUS' ? 'BONUS_PUBLISHED' : 'PAYROLL_PUBLISHED',
            'title' => $title,
            'body' => $targetYearMonth . ' の' . (strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細') . 'を公開しました。',
            'publish_start_at' => $publishedAt,
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
            'sent_at' => $publishedAt,
            'read_at' => null,
        ]);

        if ($version !== null) {
            // Database queue shares this transaction: no delivery can precede row commit.
            $job = new \App\Jobs\SendPayrollPublication($statementId, $version, $publishedAt->format('Y-m-d H:i:s'));
            app('queue')->connection('database')->later($publishedAt, $job, '', 'staffhub-notifications');
            DB::table('audit_logs')->insert(['actor_type'=>'SYSTEM','actor_id'=>null,
                'action'=>'PAYROLL_NOTIFICATION_QUEUED','target_type'=>'PAYROLL_NOTIFICATION','target_id'=>$job->eventId(),
                'detail_json'=>json_encode(['statementId'=>$statementId,'employeeId'=>$employeeId,'targetYearMonth'=>$targetYearMonth,
                    'publishedAt'=>$publishedAt->toIso8601String()],JSON_UNESCAPED_UNICODE),'occurred_at'=>now()]);
            return;
        }

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
        $now = now();
        $status = $row->stopped_at !== null ? 'STOPPED' : (CarbonImmutable::parse($row->publish_start_at)->isFuture() ? 'SCHEDULED'
            : ($row->publish_end_at !== null && CarbonImmutable::parse($row->publish_end_at)->lessThanOrEqualTo($now) ? 'ENDED' : 'PUBLISHED'));
        $notificationStatus = $row->notification_sent_at !== null ? 'SENT'
            : ($row->notification_queued_at === null ? 'UNKNOWN' : ($status === 'STOPPED' || $status === 'ENDED' ? 'CANCELLED' : ($status === 'SCHEDULED' ? 'SCHEDULED' : 'QUEUED')));
        if ($notificationStatus === 'QUEUED' && DB::table('audit_logs')->where('target_type', 'NOTICE_NOTIFICATION')
            ->where('target_id', 'notice-'.$row->id)->where('action', 'NOTICE_NOTIFICATION_INCOMPLETE')->exists()) {
            $notificationStatus = 'INCOMPLETE';
        }
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
            'revision' => (int) $row->revision, 'publicationStatus' => $status, 'notificationStatus' => $notificationStatus,
            'stoppedAt' => $row->stopped_at ? CarbonImmutable::parse($row->stopped_at)->toIso8601String() : null,
            'updatedAt' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
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
