<?php

namespace App\Jobs;

use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SendPayrollPublication implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 40;

    public function __construct(public int $statementId, public string $version, public string $publishedAt)
    {
        $this->onConnection('database')->onQueue('staffhub-notifications');
    }

    public function backoff(): array { return [60, 300, 900, 3600]; }

    public function eventId(): string
    {
        return 'payroll-'.hash('sha256', $this->statementId.'|'.$this->version.'|'.$this->publishedAt);
    }

    public function handle(NotificationMailService $notifications): void
    {
        $statement = DB::table('payroll_statements')->where('id', $this->statementId)->first();
        // Replaced, deleted or rescheduled statements invalidate an old publication job.
        if ($statement === null || $statement->deleted_at !== null || $statement->file_path !== $this->version
            || $statement->published_at !== $this->publishedAt) { return; }
        if (CarbonImmutable::parse($statement->published_at)->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds(CarbonImmutable::parse($statement->published_at))));
            return;
        }
        $eventId = $this->eventId();
        if (DB::table('audit_logs')->where('action', 'PAYROLL_NOTIFICATION_SENT')->where('target_id', $eventId)->exists()) { return; }
        $result = $notifications->sendPayrollPublished((int) $statement->employee_id, $statement->statement_type,
            $statement->target_year_month, $this->statementId, \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, $eventId)->toString());
        DB::table('audit_logs')->insert([
            'actor_type' => 'SYSTEM', 'actor_id' => null,
            'action' => $result['ok'] ? 'PAYROLL_NOTIFICATION_SENT' : 'PAYROLL_NOTIFICATION_INCOMPLETE',
            'target_type' => 'PAYROLL_NOTIFICATION', 'target_id' => $eventId,
            'detail_json' => json_encode(['statementId' => $this->statementId, 'employeeId' => (int) $statement->employee_id,
                'targetYearMonth' => $statement->target_year_month, 'status' => $result['status'],
                'reason' => $result['reason'], 'messageName' => $result['message_name']], JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
        if (!$result['ok']) { throw new RuntimeException('給与は保存済みです。公開通知の送信は未完了です。'); }
    }
}
