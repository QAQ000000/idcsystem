<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_when_all_checks_pass(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->getJson(route('health'))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok')
            ->assertJsonPath('checks.storage.status', 'ok')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'database' => ['status', 'message'],
                    'redis' => ['status', 'message'],
                    'queue' => ['status', 'message', 'recent_failed_jobs'],
                    'storage' => ['status', 'message', 'free_bytes', 'total_bytes', 'free_percent'],
                ],
            ]);
    }

    public function test_health_endpoint_returns_degraded_when_recent_failed_jobs_exist(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        DB::table('failed_jobs')->insert([
            'uuid' => 'health-test-failed-job',
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Health test failure',
            'failed_at' => now(),
        ]);

        $this->getJson(route('health'))
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.status', 'error')
            ->assertJsonPath('checks.queue.recent_failed_jobs', 1);
    }
}
