<?php

declare(strict_types=1);

return [
    'poll_interval' => 5000,
    'job_navigation_breakdown' => false,
    'job_payload_allowed_classes' => [],
    'bulk_operations' => [
        'connection' => null,
        'queue' => null,
    ],
    'signals' => [
        'store' => null,
        'ttl' => 86400,
    ],
    'relay' => [
        'store' => null,
        'ttl' => 3600,
    ],
    'chunks' => [
        'store' => null,
        'ttl' => 86400,
    ],
    'chains' => [
        'store' => null,
    ],
];
