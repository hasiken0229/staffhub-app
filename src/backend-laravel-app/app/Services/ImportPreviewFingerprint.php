<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

final class ImportPreviewFingerprint
{
    public static function make(array $payload, UploadedFile $file): string
    {
        unset($payload['file'], $payload['previewFingerprint']);
        $payload = array_map(static fn ($value) => $value === null ? '' : (string) $value, $payload);
        $payload = array_filter($payload, static fn ($value) => $value !== '');
        ksort($payload);
        $content = hash_file('sha256', $file->getRealPath()).'|'.json_encode($payload, JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', $content, (string) config('app.key'));
    }

    public static function assertMatches(?string $expected, array $payload, UploadedFile $file): void
    {
        // Optional for existing API clients. The current UI always sends its preview fingerprint.
        if ($expected !== null && $expected !== '' && !hash_equals(self::make($payload, $file), $expected)) {
            throw new ApiException('PREVIEW_CHANGED', 'プレビュー後にファイルまたは条件が変わりました。プレビューを取り直してください。', 409);
        }
    }
}
