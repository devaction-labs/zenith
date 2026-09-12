<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * The three fixed retention tiers the recorder writes every event into.
 *
 * Each tier trades bucket width for retention: fine buckets are cheap to
 * discard quickly, coarse buckets stay useful for longer at a lower
 * resolution. Defaults match the ADR in `docs/architecture.md`: 1 second
 * buckets for 15 minutes, 1 minute buckets for 24 hours, 5 minute buckets
 * for 7 days. Consumers may override either number per tier through
 * `config('zenith.telemetry.retention')`.
 */
enum TelemetryResolution: string
{
    case Fine = 'fine';
    case Standard = 'standard';
    case Coarse = 'coarse';

    public function bucketSeconds(): int
    {
        return $this->configuredInt('bucket_seconds', match ($this) {
            self::Fine => 1,
            self::Standard => 60,
            self::Coarse => 300,
        });
    }

    public function retentionSeconds(): int
    {
        return $this->configuredInt('ttl_seconds', match ($this) {
            self::Fine => 900,
            self::Standard => 86_400,
            self::Coarse => 604_800,
        });
    }

    public function bucketStart(int $timestamp): int
    {
        $width = $this->bucketSeconds();

        if ($width <= 0) {
            return $timestamp;
        }

        return intdiv($timestamp, $width) * $width;
    }

    private function configuredInt(string $key, int $default): int
    {
        $value = config("zenith.telemetry.retention.{$this->value}.{$key}");

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
