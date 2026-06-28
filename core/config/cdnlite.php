<?php

return [
    'api_token' => env('CDNLITE_API_TOKEN', ''),
    'cors_allowed_origins' => env('CDNLITE_CORS_ALLOWED_ORIGINS', 'http://localhost:8082,http://127.0.0.1:8082'),
    'bootstrap_admin_user' => env('CDNLITE_BOOTSTRAP_ADMIN_USER', true),
    'admin_session_ttl_seconds' => (int) env('CDNLITE_ADMIN_SESSION_TTL_SECONDS', 28800),
    'edge' => [
        'bootstrap_token' => env('CDNLITE_BOOTSTRAP_EDGE_TOKEN', true),
        'id' => env('CDNLITE_BOOTSTRAP_EDGE_ID', env('EDGE_ID', '')),
        'token' => env('CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE', env('EDGE_TOKEN', '')),
    ],
    'powerdns' => [
        'verify_after_write' => env('CDNLITE_POWERDNS_VERIFY_AFTER_WRITE', true),
        'retries' => (int) env('CDNLITE_POWERDNS_RETRIES', 3),
        'retry_sleep_ms' => (int) env('CDNLITE_POWERDNS_RETRY_SLEEP_MS', 250),
        'timeout_seconds' => (int) env('CDNLITE_POWERDNS_TIMEOUT_SECONDS', 10),
    ],
];
