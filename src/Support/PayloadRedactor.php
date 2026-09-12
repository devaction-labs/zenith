<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

use DevactionLabs\Zenith\Zenith;
use Illuminate\Support\Str;

final class PayloadRedactor
{
    public const string REDACTED_VALUE = '[REDACTED]';

    /**
     * Redact sensitive values from a job, failed-job, or workflow payload
     * before it reaches Inertia props. A registered `Zenith::redactPayloadUsing()`
     * callback fully replaces the default key-pattern redaction; otherwise every
     * key matching a configured `zenith.redact_payload_keys` pattern is masked,
     * recursively, wherever it appears in the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $callback = Zenith::redactPayloadCallback();

        if ($callback !== null) {
            $result = $callback($payload);

            return is_array($result) ? self::withStringKeys($result) : $payload;
        }

        return self::redactRecursively($payload, self::patterns());
    }

    /** @return array<int, string> */
    private static function patterns(): array
    {
        $configured = config('zenith.redact_payload_keys', []);

        if (! is_array($configured)) {
            return [];
        }

        $patterns = [];

        foreach ($configured as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $patterns[] = Str::lower($pattern);
        }

        return $patterns;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $patterns
     * @return array<string, mixed>
     */
    private static function redactRecursively(array $value, array $patterns): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (self::matchesPattern($key, $patterns)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            $redacted[$key] = is_array($item) ? self::redactNestedArray($item, $patterns) : $item;
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<int, string>  $patterns
     * @return array<array-key, mixed>
     */
    private static function redactNestedArray(array $value, array $patterns): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && self::matchesPattern($key, $patterns)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            $redacted[$key] = is_array($item) ? self::redactNestedArray($item, $patterns) : $item;
        }

        return $redacted;
    }

    /** @param array<int, string> $patterns */
    private static function matchesPattern(string $key, array $patterns): bool
    {
        $normalizedKey = Str::lower($key);

        foreach ($patterns as $pattern) {
            if (str_contains($normalizedKey, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function withStringKeys(array $value): array
    {
        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
