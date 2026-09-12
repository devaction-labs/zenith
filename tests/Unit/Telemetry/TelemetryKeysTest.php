<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\TelemetryDimension;
use DevactionLabs\Zenith\Telemetry\TelemetryKeys;
use DevactionLabs\Zenith\Telemetry\TelemetryMetric;
use DevactionLabs\Zenith\Telemetry\TelemetryOutcome;

it('parses a count field it wrote back into its parts', function (): void {
    $field = TelemetryKeys::countField(TelemetryDimension::Queue, 'emails', TelemetryOutcome::Failed);

    expect(TelemetryKeys::parseField($field))->toBe([
        'kind' => 'count',
        'dimension' => TelemetryDimension::Queue,
        'value' => 'emails',
        'outcome' => TelemetryOutcome::Failed,
    ]);
});

it('parses a histogram field it wrote back into its parts', function (): void {
    $field = TelemetryKeys::histogramField(TelemetryMetric::Wait, TelemetryDimension::JobClass, 'App\\Jobs\\Import', 7);

    expect(TelemetryKeys::parseField($field))->toBe([
        'kind' => 'hist',
        'metric' => TelemetryMetric::Wait,
        'dimension' => TelemetryDimension::JobClass,
        'value' => 'App\\Jobs\\Import',
        'bucketIndex' => 7,
    ]);
});

it('returns null for anything that is not a recognized field shape', function (string $field): void {
    expect(TelemetryKeys::parseField($field))->toBeNull();
})->with([
    'unrelated string' => 'not-a-field',
    'unknown prefix' => "unknown\x1fqueue\x1fdefault\x1fprocessed",
    'unknown outcome' => "count\x1fqueue\x1fdefault\x1fmystery",
    'unknown dimension' => "count\x1fteam\x1fdefault\x1fprocessed",
    'non numeric bucket index' => "hist\x1fruntime\x1fqueue\x1fdefault\x1fabc",
    'wrong arity' => "count\x1fqueue\x1fdefault",
]);
