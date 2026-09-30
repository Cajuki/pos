<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_database_connectivity(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    public function test_health_returns_service_unavailable_when_database_is_down(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new \PDOException('database unavailable'));

        $this->getJson('/api/v1/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('database', 'unavailable');
    }
}
