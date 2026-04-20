<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class NotificationMailService
{
    public function __construct(
        private readonly GoogleChatNotificationService $googleChatNotificationService,
        private readonly GoogleChatRecipientResolver $recipientResolver,
        private readonly GoogleChatMessageBuilder $googleChatMessageBuilder,
    ) {
    }

    public function sendNoticePublished(
        ?int $targetEmployeeId,
        string $noticeType,
        string $title,
        string $body,
        array $context = [],
    ): void {
        $title = trim($title);
        $body = trim($body);
        $baseContext = array_filter([
            '通知区分' => strtoupper($noticeType),
            ...$context,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($targetEmployeeId !== null) {
            $employee = $this->loadEmployee($targetEmployeeId);
            $recipient = $this->recipientResolver->resolveEmployeeUserId($targetEmployeeId);
            $message = $this->buildTemplateMessage('notice_targeted', [
                'title' => $title,
                'body' => $body,
            ]);

            if ($recipient === null) {
                $this->logSkipped('NOTICE_TARGETED', [
                    'employeeId' => $targetEmployeeId,
                    'title' => $title,
                    'reason' => 'missing_google_chat_user_id',
                ]);

                return;
            }

            $result = $this->googleChatNotificationService->sendDirectMessage(
                $recipient,
                $message['title'],
                $message['lines'],
                array_filter([
                    '対象職員' => $employee !== null
                        ? trim(($employee->employee_code ?? '') . ' ' . ($employee->name ?? ''))
                        : null,
                    ...$baseContext,
                ], static fn (mixed $value): bool => $value !== null && $value !== '')
            );

            $this->logResult('NOTICE_TARGETED', $result, [
                'employeeId' => $targetEmployeeId,
                'title' => $title,
            ]);

            return;
        }

        $message = $this->buildTemplateMessage('notice_all_staff', [
            'title' => $title,
            'body' => $body,
        ]);
        $spaceId = $this->recipientResolver->resolveAllStaffSpaceId();
        if ($spaceId === null) {
            $this->logSkipped('NOTICE_ALL_STAFF', [
                'title' => $title,
                'reason' => 'all_staff_space_not_configured',
            ]);

            return;
        }

        $result = $this->googleChatNotificationService->sendAllStaffSpaceMessage(
            $message['title'],
            $message['lines'],
            $baseContext
        );

        $this->logResult('NOTICE_ALL_STAFF', $result, [
            'title' => $title,
            'spaceId' => $spaceId,
        ]);
    }

    public function sendPayrollPublished(
        int $employeeId,
        string $statementType,
        string $targetYearMonth,
        int $statementId,
    ): void {
        $statementLabel = strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細';
        $employee = $this->loadEmployee($employeeId);
        $recipient = $this->recipientResolver->resolveEmployeeUserId($employeeId);
        $message = $this->buildTemplateMessage('payroll_published', [
            'statementLabel' => $statementLabel,
            'targetYearMonth' => $targetYearMonth,
        ]);

        if ($recipient === null) {
            $this->logSkipped('PAYROLL_PUBLISHED', [
                'employeeId' => $employeeId,
                'statementId' => $statementId,
                'reason' => 'missing_google_chat_user_id',
            ]);

            return;
        }

        $result = $this->googleChatNotificationService->sendDirectMessage(
            $recipient,
            $message['title'],
            $message['lines'],
            array_filter([
                '対象職員' => $employee !== null
                    ? trim(($employee->employee_code ?? '') . ' ' . ($employee->name ?? ''))
                    : null,
                '対象年月' => $targetYearMonth,
                '明細種別' => strtoupper($statementType),
                '関連ID' => (string) $statementId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );

        $this->logResult('PAYROLL_PUBLISHED', $result, [
            'employeeId' => $employeeId,
            'statementId' => $statementId,
        ]);
    }

    public function sendLeaveDecision(
        int $employeeId,
        int $requestId,
        string $decision,
        ?string $comment,
    ): void {
        $employee = $this->loadEmployee($employeeId);
        $recipient = $this->recipientResolver->resolveEmployeeUserId($employeeId);
        $normalizedDecision = strtolower(trim($decision));
        $message = $this->buildTemplateMessage('leave_decision_' . $normalizedDecision, [
            'commentOrDefault' => $comment ?: '休暇申請の状態が更新されました。',
        ]);

        if ($recipient === null) {
            $this->logSkipped('LEAVE_DECISION', [
                'employeeId' => $employeeId,
                'requestId' => $requestId,
                'decision' => strtoupper($decision),
                'reason' => 'missing_google_chat_user_id',
            ]);

            return;
        }

        $result = $this->googleChatNotificationService->sendDirectMessage(
            $recipient,
            $message['title'],
            $message['lines'],
            array_filter([
                '対象職員' => $employee !== null
                    ? trim(($employee->employee_code ?? '') . ' ' . ($employee->name ?? ''))
                    : null,
                '判定' => strtoupper($decision),
                '申請ID' => (string) $requestId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );

        $this->logResult('LEAVE_DECISION', $result, [
            'employeeId' => $employeeId,
            'requestId' => $requestId,
            'decision' => strtoupper($decision),
        ]);
    }

    public function sendLeaveRequestCreated(
        int $employeeId,
        int $requestId,
        string $leaveTypeName,
        string $startDate,
        string $endDate,
        float $quantityDays,
        ?string $reason,
    ): void {
        $spaceId = $this->recipientResolver->resolveAdminSpaceId();
        $employee = $this->loadEmployee($employeeId);
        $message = $this->buildTemplateMessage('leave_request_created', [
            'employeeName' => (string) ($employee?->name ?? '職員'),
        ]);

        if ($spaceId === null) {
            $this->logSkipped('LEAVE_REQUEST_CREATED', [
                'employeeId' => $employeeId,
                'requestId' => $requestId,
                'reason' => 'admin_space_not_configured',
            ]);

            return;
        }

        $result = $this->googleChatNotificationService->sendAdminSpaceMessage(
            $message['title'],
            $message['lines'],
            array_filter([
                '対象職員' => $employee !== null
                    ? trim(($employee->employee_code ?? '') . ' ' . ($employee->name ?? ''))
                    : null,
                '休暇区分' => $leaveTypeName,
                '申請期間' => $startDate === $endDate ? $startDate : ($startDate . ' - ' . $endDate),
                '申請日数' => (string) $quantityDays,
                '理由' => $reason,
                '申請ID' => (string) $requestId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );

        $this->logResult('LEAVE_REQUEST_CREATED', $result, [
            'employeeId' => $employeeId,
            'requestId' => $requestId,
            'spaceId' => $spaceId,
        ]);
    }

    public function sendAttendanceAlert(
        string $alertCode,
        string $title,
        array $lines,
        array $context = [],
    ): void {
        $spaceId = $this->recipientResolver->resolveAdminSpaceId();
        if ($spaceId === null) {
            $this->logSkipped('ATTENDANCE_ALERT', [
                'alertCode' => $alertCode,
                'title' => $title,
                'reason' => 'admin_space_not_configured',
            ]);

            return;
        }

        $message = $this->buildAlertMessage($alertCode, $title, $lines, $context);
        $result = $this->googleChatNotificationService->sendAdminSpaceMessage(
            $message['title'],
            $message['lines'],
            array_filter([
                'アラート種別' => $alertCode,
                ...$context,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );

        $this->logResult('ATTENDANCE_ALERT', $result, [
            'alertCode' => $alertCode,
            'title' => $title,
            'spaceId' => $spaceId,
        ]);
    }

    private function loadEmployee(int $employeeId): ?object
    {
        return DB::table('employees')
            ->select(['id', 'employee_code', 'name'])
            ->where('id', $employeeId)
            ->first();
    }

    /**
     * @param array<string, scalar|null> $variables
     * @return array{title: string, lines: array<int, string>, context: array<string, string>}
     */
    private function buildTemplateMessage(string $templateKey, array $variables): array
    {
        $template = config('staffhub.google_chat.templates.' . $templateKey);
        if (!is_array($template)) {
            $template = [];
        }

        return $this->googleChatMessageBuilder->buildMessage($template, $variables);
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, mixed> $context
     * @return array{title: string, lines: array<int, string>, context: array<string, string>}
     */
    private function buildAlertMessage(string $alertCode, string $fallbackTitle, array $lines, array $context): array
    {
        $templateKey = 'attendance_alert_' . strtolower(trim($alertCode));
        $employeeName = isset($context['対象職員']) ? preg_replace('/^[^\s]+\s+/u', '', (string) $context['対象職員']) : null;
        $message = $this->buildTemplateMessage($templateKey, [
            'employeeName' => $employeeName ?: '職員',
        ]);

        if ($message['title'] === '' && $message['lines'] === []) {
            return [
                'title' => $fallbackTitle,
                'lines' => $lines,
                'context' => [],
            ];
        }

        return $message;
    }

    /**
     * @param array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null} $result
     * @param array<string, mixed> $context
     */
    private function logResult(string $event, array $result, array $context): void
    {
        $payload = [
            'event' => $event,
            'status' => $result['status'],
            'targetType' => $result['target_type'],
            'target' => $result['target'],
            'space' => $result['space'],
            'messageName' => $result['message_name'],
            'reason' => $result['reason'],
            ...$context,
        ];

        if ($result['ok']) {
            Log::info('notification chat delivery completed', $payload);
            return;
        }

        Log::warning('notification chat delivery incomplete', $payload);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logSkipped(string $event, array $context): void
    {
        Log::warning('notification chat delivery skipped', [
            'event' => $event,
            ...$context,
        ]);
    }
}
