<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersApiTest extends TestCase
{
    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('https://localhost/api/auth/me');

        $response
            ->assertStatus(401)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_api_error_responses_are_private_and_not_stored(): void
    {
        $response = $this->getJson('/api/auth/me')->assertStatus(401);

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_static_asset_cache_policy_is_unchanged_outside_api(): void
    {
        foreach (['js' => 'application/javascript', 'css' => 'text/css'] as $extension => $contentType) {
            $url = '/cache-test/static.'.$extension;
            Route::get($url, fn () => response('/* synthetic asset */', 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]));

            $response = $this->get($url)->assertOk();

            $this->assertTrue($response->headers->hasCacheControlDirective('public'));
            $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
            $this->assertSame('31536000', (string) $response->headers->getCacheControlDirective('max-age'));
            $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        }
    }
}
