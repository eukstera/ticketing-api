<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsTest extends TestCase
{
    /**
     * The real .env may add origins (e.g. a LAN address), so only the dev
     * frontends are required to be present, and the list must be clean.
     */
    public function test_allowed_origins_include_the_dev_frontends(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('http://localhost:3000', $origins);
        $this->assertContains('https://snaking-easing-mystify.ngrok-free.dev', $origins);
        $this->assertSame($origins, array_values(array_unique(array_filter(array_map('trim', $origins)))));
        $this->assertNotContains('*', $origins);
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

    public function test_a_server_error_still_carries_the_cors_headers(): void
    {
        Route::get('/api/_cors_boom', fn () => throw new \RuntimeException('boom'));

        $response = $this->getJson('/api/_cors_boom', ['Origin' => 'http://localhost:3000']);

        $response->assertStatus(500);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_a_guest_without_an_accept_header_gets_a_401_json_answer_with_cors_headers(): void
    {
        $response = $this->call('GET', '/api/tickets/new', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:3000']);

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertFalse($response->json('success'));
        $this->assertNull($response->headers->get('Location'));
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
