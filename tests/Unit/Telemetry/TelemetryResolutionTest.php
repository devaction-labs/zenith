<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\TelemetryResolution;

it('defaults to one second buckets kept for fifteen minutes', function (): void {
    expect(TelemetryResolution::Fine->bucketSeconds())->toBe(1);
    expect(TelemetryResolution::Fine->retentionSeconds())->toBe(900);
});

it('defaults to one minute buckets kept for twenty four hours', function (): void {
    expect(TelemetryResolution::Standard->bucketSeconds())->toBe(60);
    expect(TelemetryResolution::Standard->retentionSeconds())->toBe(86_400);
});

it('defaults to five minute buckets kept for seven days', function (): void {
    expect(TelemetryResolution::Coarse->bucketSeconds())->toBe(300);
    expect(TelemetryResolution::Coarse->retentionSeconds())->toBe(604_800);
});

it('floors a timestamp to its bucket boundary', function (): void {
    expect(TelemetryResolution::Standard->bucketStart(1_784_281_123))->toBe(1_784_281_080);
    expect(TelemetryResolution::Fine->bucketStart(1_784_281_123))->toBe(1_784_281_123);
    expect(TelemetryResolution::Coarse->bucketStart(1_784_281_123))->toBe(1_784_280_900);
});

it('honors configured retention overrides', function (): void {
    config()->set('zenith.telemetry.retention.fine.bucket_seconds', 2);
    config()->set('zenith.telemetry.retention.fine.ttl_seconds', 120);

    expect(TelemetryResolution::Fine->bucketSeconds())->toBe(2);
    expect(TelemetryResolution::Fine->retentionSeconds())->toBe(120);
});

it('ignores non positive configured overrides and falls back to the default', function (mixed $override): void {
    config()->set('zenith.telemetry.retention.standard.bucket_seconds', $override);

    expect(TelemetryResolution::Standard->bucketSeconds())->toBe(60);
})->with([
    'zero' => 0,
    'negative' => -10,
    'non numeric' => 'soon',
    'missing' => null,
]);
