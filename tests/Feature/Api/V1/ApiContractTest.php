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
}
