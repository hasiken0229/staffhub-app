<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CorsApiTest extends TestCase
{
    public function test_api_preflight_allows_the_configured_production_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://attendance.example.org',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://attendance.example.org');
    }

    public function test_api_preflight_does_not_grant_an_untrusted_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://untrusted.example.invalid',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            // A single fixed allowed origin is returned by Laravel for every
            // preflight. Browsers reject it because it does not match this
            // request's Origin; the important point is that it is never `*`
            // or the untrusted origin.
            ->assertHeader('Access-Control-Allow-Origin', 'https://attendance.example.org');
    }
}
