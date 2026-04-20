<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleChatTokenService
{
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function currentAccessToken(): ?string
    {
        $token = $this->issueAccessToken();

        return $token['access_token'] ?? null;
    }

    /**
     * @return array{access_token: string, expires_at: int}|null
     */
    public function issueAccessToken(): ?array
    {
        $this->ensureCurlTlsConstants();

        if (!(bool) config('staffhub.google_chat.enabled')) {
            return null;
        }

        $credentialsPath = config('staffhub.google_chat.credentials_path');
        if (!is_string($credentialsPath) || trim($credentialsPath) === '') {
            return null;
        }

        $scope = trim((string) config('staffhub.google_chat.bot_scope', 'https://www.googleapis.com/auth/chat.bot'));
        $cacheKey = 'staffhub_google_chat_token_' . sha1($credentialsPath . '|' . $scope);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['access_token'], $cached['expires_at']) && (int) $cached['expires_at'] > time()) {
            return [
                'access_token' => (string) $cached['access_token'],
                'expires_at' => (int) $cached['expires_at'],
            ];
        }

        $credentials = $this->loadCredentials($credentialsPath);
        $assertion = $this->buildSignedJwt($credentials, $scope);
        $timeoutSeconds = max(1, (int) config('staffhub.google_chat.message_timeout_seconds', 10));
        $tokenUri = isset($credentials['token_uri']) && is_string($credentials['token_uri']) && trim($credentials['token_uri']) !== ''
            ? trim($credentials['token_uri'])
            : self::DEFAULT_TOKEN_URI;

        $response = Http::asForm()
            ->timeout($timeoutSeconds)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])
            ->throw()
            ->json();

        $accessToken = isset($response['access_token']) ? trim((string) $response['access_token']) : '';
        if ($accessToken === '') {
            throw new RuntimeException('Google Chat access token response did not contain access_token.');
        }

        $expiresIn = max(60, (int) ($response['expires_in'] ?? 3600));
        $expiresAt = time() + max(60, $expiresIn - 60);

        $token = [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
        ];

        Cache::put($cacheKey, $token, $expiresAt - time());

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri?: string}
     */
    private function loadCredentials(string $credentialsPath): array
    {
        if (!is_file($credentialsPath) || !is_readable($credentialsPath)) {
            throw new RuntimeException('Google Chat credentials file is not readable: ' . $credentialsPath);
        }

        $decoded = json_decode((string) file_get_contents($credentialsPath), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Chat credentials file is not valid JSON.');
        }

        $clientEmail = isset($decoded['client_email']) ? trim((string) $decoded['client_email']) : '';
        $privateKey = isset($decoded['private_key']) ? trim((string) $decoded['private_key']) : '';
        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('Google Chat credentials file is missing client_email or private_key.');
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'token_uri' => isset($decoded['token_uri']) ? trim((string) $decoded['token_uri']) : self::DEFAULT_TOKEN_URI,
        ];
    }

    /**
     * @param array{client_email: string, private_key: string, token_uri?: string} $credentials
     */
    private function buildSignedJwt(array $credentials, string $scope): string
    {
        $issuedAt = time();
        $tokenUri = isset($credentials['token_uri']) && $credentials['token_uri'] !== ''
            ? $credentials['token_uri']
            : self::DEFAULT_TOKEN_URI;

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => $scope,
            'aud' => $tokenUri,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $signingInput = $header . '.' . $payload;
        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            throw new RuntimeException('Failed to sign Google Chat JWT assertion.');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ensureCurlTlsConstants(): void
    {
        if (extension_loaded('curl') && !defined('CURL_SSLVERSION_TLSv1_2')) {
            define('CURL_SSLVERSION_TLSv1_2', 6);
        }
    }
}
