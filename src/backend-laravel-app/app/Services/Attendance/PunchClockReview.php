<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

final class PunchClockReview
{
    public const REASON = 'DEVICE_CLOCK_REVIEW_REQUIRED';

    // The Windows terminal treats a drift exceeding two minutes as a clock error.
    private const DRIFT_LIMIT_SECONDS = 120;
    private const HEARTBEAT_WINDOW_SECONDS = 300;

    public static function requiresReview(CarbonImmutable $occurredAt, CarbonImmutable $receivedAt, array $payload): bool
    {
        $ahead = $occurredAt->getTimestamp() - $receivedAt->getTimestamp();
        if (($payload['captureClockUnknown'] ?? false)
            || $ahead > self::DRIFT_LIMIT_SECONDS || $occurredAt->toDateString() > $receivedAt->toDateString()) {
            return true;
        }

        // This estimate was saved with the card scan and survives clock changes and queue retries.
        if (isset($payload['clockReferenceAt'])) {
            $reference = CarbonImmutable::parse($payload['clockReferenceAt'])->setTimezone($occurredAt->timezone);
            return $occurredAt->toDateString() !== $reference->toDateString()
                || abs($occurredAt->getTimestamp() - $reference->getTimestamp()) > self::DRIFT_LIMIT_SECONDS;
        }
        if (isset($payload['captureAgeSeconds'])) {
            $reference = $receivedAt->subSeconds((int) $payload['captureAgeSeconds']);
            $difference = $occurredAt->getTimestamp() - $reference->getTimestamp();
            if ($occurredAt->toDateString() === $reference->toDateString()) {
                return abs($difference) > self::DRIFT_LIMIT_SECONDS;
            }
            // Age is measured before sending. A valid 23:59:59 scan may reach this endpoint
            // after midnight. Account only for the observed transit within the terminal's HTTP timeout.
            $transit = isset($payload['clientSentAt'])
                ? $receivedAt->getTimestamp() - CarbonImmutable::parse($payload['clientSentAt'])->getTimestamp() : 0;
            return $difference > 0 || $transit < 0 || $transit > 15 || -$difference > $transit + 1;
        }

        // Without a capture sample, a bad send-time clock is evidence only for a nearby scan.
        if (isset($payload['clientSentAt'])) {
            $sentAt = CarbonImmutable::parse($payload['clientSentAt'])->getTimestamp();
            return abs($sentAt - $receivedAt->getTimestamp()) > self::DRIFT_LIMIT_SECONDS
                && abs($sentAt - $occurredAt->getTimestamp()) <= self::HEARTBEAT_WINDOW_SECONDS;
        }

        return false;
    }

    public static function matchesBadHeartbeat(object $device, CarbonImmutable $occurredAt, CarbonImmutable $receivedAt): bool
    {
        $drift = (int) ($device->clock_drift_seconds ?? 0);
        if (abs($drift) <= self::DRIFT_LIMIT_SECONDS || empty($device->last_seen_at)) {
            return false;
        }

        $heartbeatAt = CarbonImmutable::parse($device->last_seen_at)->getTimestamp();
        $age = $receivedAt->getTimestamp() - $heartbeatAt;
        if ($age < 0 || $age > self::HEARTBEAT_WINDOW_SECONDS) {
            return false;
        }

        // A recently observed bad clock must not quarantine unrelated, older queued scans.
        $expectedTerminalTime = $receivedAt->getTimestamp() + $drift;
        return abs($occurredAt->getTimestamp() - $expectedTerminalTime) <= self::HEARTBEAT_WINDOW_SECONDS;
    }
}
