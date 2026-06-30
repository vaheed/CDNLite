<?php

return [
    'api_token' => env('CDNLITE_API_TOKEN', ''),
    'cors_allowed_origins' => env('CDNLITE_CORS_ALLOWED_ORIGINS', 'http://localhost:8082,http://127.0.0.1:8082'),
    'dev_admin_username' => env('CDNLITE_DEV_ADMIN_USERNAME', 'admin@example.test'),
    'admin_session_ttl_seconds' => (int) env('CDNLITE_ADMIN_SESSION_TTL_SECONDS', 28800),
    'edge' => [
        'id' => env('EDGE_ID', 'edge-local-1'),
        'token' => env('EDGE_TOKEN', 'edge-dev-token'),
        'config_max_bytes' => (int) env('CDNLITE_EDGE_CONFIG_MAX_BYTES', 1048576),
        'snapshot_history_enabled' => env('CDNLITE_CONFIG_SNAPSHOT_HISTORY_ENABLED', false),
        'snapshot_rollback_enabled' => env('CDNLITE_CONFIG_SNAPSHOT_ROLLBACK_ENABLED', false),
    ],
    'powerdns' => [
        'verify_after_write' => env('CDNLITE_POWERDNS_VERIFY_AFTER_WRITE', true),
        'retries' => (int) env('CDNLITE_POWERDNS_RETRIES', 3),
        'retry_sleep_ms' => (int) env('CDNLITE_POWERDNS_RETRY_SLEEP_MS', 250),
        'timeout_seconds' => (int) env('CDNLITE_POWERDNS_TIMEOUT_SECONDS', 10),
    ],
    'ssl' => [
        'scheduler_interval_seconds' => (int) env('CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS', 30),
    ],
];
