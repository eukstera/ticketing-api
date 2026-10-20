<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_allowed_origins_default_to_the_dev_frontends(): void
    {
        $this->assertSame(
            ['http://localhost:3000', 'https://snaking-easing-mystify.ngrok-free.dev'],
            config('cors.allowed_origins')
        );
    }

    public function test_preflight_from_a_known_origin_is_allowed_with_ngrok_header(): void
    {
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://snaking-easing-mystify.ngrok-free.dev',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,ngrok-skip-browser-warning',
        ]);

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://snaking-easing-mystify.ngrok-free.dev');
        $this->assertStringContainsString(
            'ngrok-skip-browser-warning',
            strtolower($response->headers->get('Access-Control-Allow-Headers'))
        );
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_from_an_unknown_origin_gets_no_allow_origin_header(): void
    {
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_content_disposition_is_exposed_to_the_frontend(): void
    {
        $response = $this->getJson('/api/designations?department_id=1', ['Origin' => 'http://localhost:3000']);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString(
            'content-disposition',
            strtolower($response->headers->get('Access-Control-Expose-Headers'))
        );
    }

    public function test_cors_origins_come_from_the_environment_list(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertIsArray($config['allowed_origins']);
        $this->assertFalse($config['supports_credentials']);
        $this->assertSame(['Content-Disposition'], $config['exposed_headers']);
        $this->assertContains('ngrok-skip-browser-warning', $config['allowed_headers']);
    }
}
