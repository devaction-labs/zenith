<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Recorded;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use JsonException;

/**
 * Job output recorded by RecordJobOutput for jobs carrying the Recorded
 * attribute.
 *
 * Output lives for zenith.recorded.ttl seconds in the cache store named by
 * zenith.recorded.store, or the default store when it is null, matching the
 * pattern Signal and Relay use for their own state.
 */
final class Recorded
{
    private const string PREFIX = 'zenith:recorded:';

    /**
     * The byte length a recorded value may reach before it is truncated.
     * Chosen to comfortably hold typical structured job results while
     * keeping a single recorded value well clear of the cache store's own
     * per-value limits.
     */
    private const int MAX_BYTES = 65536;

    private const string TRUNCATED_MARKER = '...[truncated]';

    public static function record(string $jobId, mixed $value): void
    {
        self::store()->put(
            self::PREFIX.$jobId,
            ['value' => self::cap(self::redact($value))],
            self::ttl(),
        );
    }

    public static function get(string $jobId): mixed
    {
        $stored = self::store()->get(self::PREFIX.$jobId);

        return is_array($stored) && array_key_exists('value', $stored) ? $stored['value'] : null;
    }

    /**
     * The single point a future redactor should filter recorded values
     * through, before they are size-capped and stored. A no-op today since
     * no PayloadRedactor exists yet in this codebase.
     */
    private static function redact(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Keep $value as-is when it (or its JSON encoding) fits within
     * MAX_BYTES, otherwise replace it with a truncated JSON string carrying
     * TRUNCATED_MARKER, since a structured value cannot be partially kept
     * once it is too large to store whole.
     */
    private static function cap(mixed $value): mixed
    {
        $encoded = self::encode($value);

        if ($encoded === null) {
            return $value;
        }

        if (strlen($encoded) <= self::MAX_BYTES) {
            return $value;
        }

        $keep = max(0, self::MAX_BYTES - strlen(self::TRUNCATED_MARKER));

        return substr($encoded, 0, $keep).self::TRUNCATED_MARKER;
    }

    private static function encode(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private static function ttl(): int
    {
        return Config::integer('zenith.recorded.ttl', 86400);
    }

    private static function store(): Repository
    {
        $store = config('zenith.recorded.store');

        return app(Factory::class)->store(is_string($store) ? $store : null);
    }
}
