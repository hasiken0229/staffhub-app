<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GoogleChatRecipientResolver
{
    public function resolveEmployeeUserId(int $employeeId): ?string
    {
        $userId = DB::table('employees')
            ->where('id', $employeeId)
            ->value('google_chat_user_id');

        if (!is_string($userId) || trim($userId) === '') {
            return null;
        }

        return $this->normalizeUserId($userId);
    }

    public function resolveAdminSpaceId(): ?string
    {
        return $this->normalizeOptionalSpaceId(config('staffhub.google_chat.admin_space_id'));
    }

    public function resolveAllStaffSpaceId(): ?string
    {
        return $this->normalizeOptionalSpaceId(config('staffhub.google_chat.all_staff_space_id'));
    }

    public function normalizeUserId(?string $userId): ?string
    {
        $normalized = trim((string) $userId);
        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, 'users/')) {
            return $normalized;
        }

        return 'users/' . ltrim($normalized, '/');
    }

    public function normalizeSpaceId(?string $spaceId): ?string
    {
        $normalized = trim((string) $spaceId);
        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, 'spaces/')) {
            return $normalized;
        }

        return 'spaces/' . ltrim($normalized, '/');
    }

    private function normalizeOptionalSpaceId(mixed $spaceId): ?string
    {
        return is_string($spaceId) ? $this->normalizeSpaceId($spaceId) : null;
    }
}
