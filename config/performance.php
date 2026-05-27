<?php

return [
    'slow_request_ms' => (int) env('PERFORMANCE_SLOW_REQUEST_MS', 200),
    'slow_query_ms' => (int) env('PERFORMANCE_SLOW_QUERY_MS', 100),
    'log_slow_requests' => env('PERFORMANCE_LOG_SLOW_REQUESTS', true),
    'log_slow_queries' => env('PERFORMANCE_LOG_SLOW_QUERIES', true),
];
