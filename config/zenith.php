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

    /*
     * Opt-in, event-driven telemetry recorder. Disabled by default: no
     * queue event listeners are registered and no data is written until
     * `enabled` is true. See docs/architecture.md#telemetry for the
     * storage shape, retention, and the fallback to Horizon snapshots
     * while the recorder is disabled.
     */
    'telemetry' => [
        'enabled' => false,

        // Overrides the node identity recorded for every event on this
        // host. Defaults to the local hostname when null.
        'node' => null,

        'retention' => [
            // 1 second buckets, kept for 15 minutes.
            'fine' => [
                'bucket_seconds' => 1,
                'ttl_seconds' => 900,
            ],
            // 1 minute buckets, kept for 24 hours.
            'standard' => [
                'bucket_seconds' => 60,
                'ttl_seconds' => 86400,
            ],
            // 5 minute buckets, kept for 7 days.
            'coarse' => [
                'bucket_seconds' => 300,
                'ttl_seconds' => 604800,
            ],
        ],
    ],
];
