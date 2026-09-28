<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * Guards the API foundation contract: versioned prefix, envelope shape and
 * error codes. These tests must keep passing as the API grows.
 */
class ApiContractTest extends TestCase
{
    public function test_api_root_returns_version_metadata(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertJsonStructure(['data' => ['name', 'version', 'documentation'], 'meta' => ['request_id']])
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_api_surface_is_versioned(): void
    {
        // The unversioned path must not resolve; v1 is the only public contract.
        $this->getJson('/api/health')->assertNotFound();
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_unknown_route_returns_the_standard_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message', 'code', 'meta' => ['request_id']])
            ->assertJsonPath('code', 'not_found');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/user')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_unauthenticated_requests_return_401_without_a_json_accept_header(): void
    {
        // Regression: Authenticate's redirect logic calls route('login') when the
        // request does not send `Accept: application/json`. This service has no
        // login route, so that must never turn into a 500 for non-SPA clients
        // (curl, uptime probes, a browser opening an API URL directly).
        foreach (['/api/v1/auth/me', '/api/v1/jobs', '/api/v1/dashboard'] as $uri) {
            $this->get($uri, ['Accept' => 'text/html'])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'unauthenticated');
        }
    }
}
