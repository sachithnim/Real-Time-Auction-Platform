<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class SpaConfigurationTest extends TestCase
{
    private const FRONTEND = 'http://localhost:5173';

    public function test_csrf_cookie_endpoint_issues_an_xsrf_token(): void
    {
        $this->get('/sanctum/csrf-cookie', ['Origin' => self::FRONTEND])
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_cors_allows_credentialed_requests_from_the_frontend_origin(): void
    {
        $this->call('OPTIONS', '/api/auth/login', server: [
            'HTTP_ORIGIN' => self::FRONTEND,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_cors_rejects_unknown_origins(): void
    {
        $response = $this->call('OPTIONS', '/api/auth/login', server: [
            'HTTP_ORIGIN' => 'http://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('http://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
