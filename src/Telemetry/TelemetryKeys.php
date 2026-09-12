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
}
