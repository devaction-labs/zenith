<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * Redis key and hash-field naming for every telemetry structure.
 *
 * Keys are prefixed with the ASCII unit separator, matching the convention
 * already used by `BulkOperationSnapshot` to keep package keys visually and
 * structurally distinct from Horizon's own.
 */
final class TelemetryKeys
{
    private const string PREFIX = "\x1fzenith:v1:telemetry:";

    public static function bucket(TelemetryResolution $resolution, int $bucketStart): string
    {
        return self::PREFIX."bucket:{$resolution->value}:{$bucketStart}";
    }

    public static function index(TelemetryResolution $resolution): string
    {
        return self::PREFIX."index:{$resolution->value}";
    }

    public static function countField(TelemetryDimension $dimension, string $value, TelemetryOutcome $outcome): string
    {
        return implode("\x1f", ['count', $dimension->value, $value, $outcome->value]);
    }

    public static function histogramField(
        TelemetryMetric $metric,
        TelemetryDimension $dimension,
        string $value,
        int $bucketIndex,
    ): string {
        return implode("\x1f", ['hist', $metric->value, $dimension->value, $value, (string) $bucketIndex]);
    }

    public static function inFlightIndex(): string
    {
        return self::PREFIX.'in-flight:index';
    }

    public static function inFlightJob(string $jobId): string
    {
        return self::PREFIX."in-flight:{$jobId}";
    }

    public static function attempts(string $jobId): string
    {
        return self::PREFIX."attempts:{$jobId}";
    }

    /**
     * Parses a bucket hash field name back into its structured meaning.
     * Returns null for anything not shaped like a field this class names
     * (including a dimension value that happens to contain the unit
     * separator, an assumption documented on the class itself).
     *
     * @return array{kind: 'count', dimension: TelemetryDimension, value: string, outcome: TelemetryOutcome}
     *                                                                                                       |array{kind: 'hist', metric: TelemetryMetric, dimension: TelemetryDimension, value: string, bucketIndex: int}
     *                                                                                                       |null
     */
    public static function parseField(string $field): ?array
    {
        $parts = explode("\x1f", $field);

        if ($parts[0] === 'count' && count($parts) === 4) {
            $dimension = TelemetryDimension::tryFrom($parts[1]);
            $outcome = TelemetryOutcome::tryFrom($parts[3]);

            if ($dimension === null || $outcome === null) {
                return null;
            }

            return ['kind' => 'count', 'dimension' => $dimension, 'value' => $parts[2], 'outcome' => $outcome];
        }

        if ($parts[0] === 'hist' && count($parts) === 5) {
            $metric = TelemetryMetric::tryFrom($parts[1]);
            $dimension = TelemetryDimension::tryFrom($parts[2]);

            if ($metric === null || $dimension === null || ! ctype_digit($parts[4])) {
                return null;
            }

            return [
                'kind' => 'hist',
                'metric' => $metric,
                'dimension' => $dimension,
                'value' => $parts[3],
                'bucketIndex' => (int) $parts[4],
            ];
        }

        return null;
    }
}
