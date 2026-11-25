<?php

namespace App\Jobs;

use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SendNoticePublication implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 40;

    public function __construct(public int $noticeId)
    {
        $this->onConnection('database')->onQueue('staffhub-notifications');
    }

    public function backoff(): array { return [60, 300, 900, 3600]; }
    public function eventId(): string { return 'notice-'.$this->noticeId; }

    public function handle(NotificationMailService $notifications): void
    {
        $failure = DB::transaction(function () use ($notifications) {
            $notice = DB::table('notices')->where('id', $this->noticeId)->lockForUpdate()->first();
            if ($notice === null || $notice->stopped_at !== null || $notice->notification_sent_at !== null
                || $notice->notification_queued_at === null) { return false; }
            if ($notice->publish_end_at !== null && CarbonImmutable::parse($notice->publish_end_at)->lessThanOrEqualTo(now())) { return false; }
            if (CarbonImmutable::parse($notice->publish_start_at)->isFuture()) {
                $this->release(max(1, (int) now()->diffInSeconds(CarbonImmutable::parse($notice->publish_start_at))));
                return false;
            }
            // Stable request ID also deduplicates a retry after Google Chat accepted an unacknowledged request.
            $result = $notifications->sendNoticePublished($notice->target_employee_id === null ? null : (int) $notice->target_employee_id,
                $notice->notice_type, $notice->title, $notice->body, ['お知らせID' => $this->noticeId],
                \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, $this->eventId())->toString());
            if ($result['ok']) {
                DB::table('notices')->where('id', $this->noticeId)->update(['notification_sent_at' => now()]);
            }
            DB::table('audit_logs')->insert([
                'actor_type' => 'SYSTEM', 'actor_id' => null,
                'action' => $result['ok'] ? 'NOTICE_NOTIFICATION_SENT' : 'NOTICE_NOTIFICATION_INCOMPLETE',
                'target_type' => 'NOTICE_NOTIFICATION', 'target_id' => $this->eventId(),
                'detail_json' => json_encode(['noticeId' => $this->noticeId, 'revision' => (int) $notice->revision,
                    'reason' => $result['reason'] ?? null, 'messageName' => $result['message_name'] ?? null], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
            ]);
            return !$result['ok'];
        });
        if ($failure) { throw new RuntimeException('お知らせは保存済みです。公開通知の送信は未完了です。'); }
    }
}
