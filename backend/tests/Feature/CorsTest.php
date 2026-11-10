<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_production_origin_can_read_the_existing_api(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);

        $this->withHeader('Origin', 'https://eneba-assignment-phi.vercel.app')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://eneba-assignment-phi.vercel.app')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_local_frontend_remains_allowed(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_preflight_allows_rest_methods_and_bearer_headers(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->call('OPTIONS', '/api/health', server: [
                'HTTP_ORIGIN' => 'https://eneba-assignment-phi.vercel.app',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, accept, x-requested-with',
            ]);

            $response->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', 'https://eneba-assignment-phi.vercel.app')
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
            $this->assertStringContainsString($method, $response->headers->get('Access-Control-Allow-Methods'));
            foreach (['authorization', 'content-type', 'accept', 'x-requested-with'] as $header) {
                $this->assertStringContainsString($header, strtolower($response->headers->get('Access-Control-Allow-Headers')));
            }
        }
    }

    public function test_foreign_origin_does_not_receive_cors_permission(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')
            ->getJson('/api/missing')
            ->assertNotFound()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/health', server: [
            'HTTP_ORIGIN' => 'https://untrusted.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_not_found_errors_include_cors_for_the_allowed_origin(): void
    {
        $this->withHeader('Origin', 'https://eneba-assignment-phi.vercel.app')
            ->getJson('/api/missing')
            ->assertNotFound()
            ->assertHeader('Access-Control-Allow-Origin', 'https://eneba-assignment-phi.vercel.app');
    }

    public function test_api_failures_still_include_cors_for_the_allowed_origin(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('Simulated database failure'));

        $this->withHeader('Origin', 'https://eneba-assignment-phi.vercel.app')
            ->getJson('/api/health')
            ->assertStatus(500)
            ->assertHeader('Access-Control-Allow-Origin', 'https://eneba-assignment-phi.vercel.app');
    }
}
