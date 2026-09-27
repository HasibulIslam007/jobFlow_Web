<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_dependency_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'checks' => ['database', 'cache'], 'environment'],
                'meta' => ['request_id', 'generated_at'],
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', true)
            ->assertJsonPath('data.checks.cache', true);
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('meta.request_id'),
        );
    }

    public function test_a_valid_inbound_request_id_is_reused(): void
    {
        $response = $this->withHeader('X-Request-Id', 'test-request-id-0001')
            ->getJson('/api/v1/health');

        $response->assertHeader('X-Request-Id', 'test-request-id-0001');
        $response->assertJsonPath('meta.request_id', 'test-request-id-0001');
    }

    public function test_platform_health_probe_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
