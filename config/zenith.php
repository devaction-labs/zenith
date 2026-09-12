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

        // In-flight ("executing now") tracking. Entries are stored with a
        // TTL derived from the job's own timeout (falling back to
        // default_timeout_seconds when the job declares none) plus
        // grace_seconds, so a crashed worker's entries still expire even
        // though no terminal event ever fires for them.
        'in_flight' => [
            'default_timeout_seconds' => 60,
            'grace_seconds' => 60,
        ],

        // Per-job attempt history, shown on job and failed-job detail
        // pages. Bounded per job (oldest attempts are trimmed first) and
        // by a TTL so a job that is never revisited does not retain its
        // history forever.
        'attempts' => [
            'per_job_limit' => 25,
            'ttl_seconds' => 604800,
        ],
    ],
];
