<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PerformanceMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_response_time_header(): void
    {
        $this->get(route('client.login'))
            ->assertOk()
            ->assertHeader('X-Response-Time');
    }

    public function test_slow_request_is_logged_without_query_string(): void
    {
        config(['performance.slow_request_ms' => 0]);
        Log::spy();

        $this->get(route('client.login', ['token' => 'secret-value']))
            ->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Slow request detected'
                    && ($context['path'] ?? null) === 'login'
                    && !str_contains(json_encode($context), 'secret-value');
            })
            ->once();
    }
}
