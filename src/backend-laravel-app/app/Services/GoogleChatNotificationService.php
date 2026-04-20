<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GoogleChatNotificationService
{
    private const CHAT_API_BASE_URL = 'https://chat.googleapis.com/v1';

    public function __construct(
        private readonly GoogleChatTokenService $tokenService,
        private readonly GoogleChatMessageBuilder $messageBuilder,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    public function sendDirectMessage(string $userId, string $title, array $lines = [], array $context = [], ?string $requestId = null): array
    {
        $normalizedUserId = $this->normalizeUserId($userId);

        try {
            $token = $this->tokenService->currentAccessToken();
            if ($token === null) {
                return $this->skippedResult('user', $normalizedUserId, 'google_chat_disabled');
            }

            $space = $this->chatRequest($token)
                ->get('spaces:findDirectMessage', [
                    'name' => $normalizedUserId,
                ]);

            $spaceName = trim((string) $space->throw()->json('name'));
            if ($spaceName === '') {
                return $this->failedResult('user', $normalizedUserId, 'direct_message_space_not_found');
            }

            return $this->sendSpaceMessageInternal($token, $spaceName, $title, $lines, $context, $requestId, 'user');
        } catch (Throwable $exception) {
            $this->logFailure('user', $normalizedUserId, $exception);

            return $this->failedResult('user', $normalizedUserId, $exception->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    public function sendAdminSpaceMessage(string $title, array $lines = [], array $context = [], ?string $requestId = null): array
    {
        $spaceId = config('staffhub.google_chat.admin_space_id');
        if (!is_string($spaceId) || trim($spaceId) === '') {
            return $this->skippedResult('space', null, 'admin_space_not_configured');
        }

        return $this->sendSpaceMessage($spaceId, $title, $lines, $context, $requestId);
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    public function sendAllStaffSpaceMessage(string $title, array $lines = [], array $context = [], ?string $requestId = null): array
    {
        $spaceId = config('staffhub.google_chat.all_staff_space_id');
        if (!is_string($spaceId) || trim($spaceId) === '') {
            return $this->skippedResult('space', null, 'all_staff_space_not_configured');
        }

        return $this->sendSpaceMessage($spaceId, $title, $lines, $context, $requestId);
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    public function sendSpaceMessage(string $spaceId, string $title, array $lines = [], array $context = [], ?string $requestId = null): array
    {
        $normalizedSpaceId = $this->normalizeSpaceId($spaceId);

        try {
            $token = $this->tokenService->currentAccessToken();
            if ($token === null) {
                return $this->skippedResult('space', $normalizedSpaceId, 'google_chat_disabled');
            }

            return $this->sendSpaceMessageInternal($token, $normalizedSpaceId, $title, $lines, $context, $requestId, 'space');
        } catch (Throwable $exception) {
            $this->logFailure('space', $normalizedSpaceId, $exception);

            return $this->failedResult('space', $normalizedSpaceId, $exception->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    private function sendSpaceMessageInternal(
        string $token,
        string $spaceId,
        string $title,
        array $lines,
        array $context,
        ?string $requestId,
        string $targetType,
    ): array {
        $response = $this->chatRequest($token)
            ->withQueryParameters($this->messageQuery($requestId))
            ->post($spaceId . '/messages', [
                'text' => $this->messageBuilder->buildText($title, $lines, $context),
            ]);

        $payload = $response->throw()->json();

        return [
            'ok' => true,
            'status' => 'sent',
            'target_type' => $targetType,
            'target' => $targetType === 'space' ? $spaceId : null,
            'space' => $spaceId,
            'message_name' => isset($payload['name']) ? (string) $payload['name'] : null,
            'reason' => null,
        ];
    }

    private function chatRequest(string $token): \Illuminate\Http\Client\PendingRequest
    {
        $timeoutSeconds = max(1, (int) config('staffhub.google_chat.message_timeout_seconds', 10));

        return Http::baseUrl(self::CHAT_API_BASE_URL)
            ->acceptJson()
            ->timeout($timeoutSeconds)
            ->withToken($token);
    }

    /**
     * @return array<string, string>
     */
    private function messageQuery(?string $requestId): array
    {
        $normalizedRequestId = trim((string) $requestId);
        if ($normalizedRequestId === '') {
            $normalizedRequestId = 'staffhub-' . Str::uuid()->toString();
        }

        return [
            'requestId' => $normalizedRequestId,
        ];
    }

    private function normalizeUserId(string $userId): string
    {
        $normalized = trim($userId);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Google Chat user ID is required.');
        }

        if (Str::startsWith($normalized, 'users/')) {
            return $normalized;
        }

        return 'users/' . ltrim($normalized, '/');
    }

    private function normalizeSpaceId(string $spaceId): string
    {
        $normalized = trim($spaceId);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Google Chat space ID is required.');
        }

        if (Str::startsWith($normalized, 'spaces/')) {
            return $normalized;
        }

        return 'spaces/' . ltrim($normalized, '/');
    }

    /**
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    private function skippedResult(string $targetType, ?string $target, string $reason): array
    {
        return [
            'ok' => false,
            'status' => 'skipped',
            'target_type' => $targetType,
            'target' => $target,
            'space' => null,
            'message_name' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{ok: bool, status: string, target_type: string, target: string|null, space: string|null, message_name: string|null, reason: string|null}
     */
    private function failedResult(string $targetType, ?string $target, string $reason): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'target_type' => $targetType,
            'target' => $target,
            'space' => null,
            'message_name' => null,
            'reason' => $reason,
        ];
    }

    private function logFailure(string $targetType, ?string $target, Throwable $exception): void
    {
        $context = [
            'targetType' => $targetType,
            'target' => $target,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof RequestException) {
            $context['responseStatus'] = $exception->response?->status();
            $context['responseBody'] = $exception->response?->body();
        }

        Log::warning('google chat notification failed', $context);
    }
}
