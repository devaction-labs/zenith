<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\DurationHistogram;
use DevactionLabs\Zenith\Telemetry\DurationPercentile;

/**
 * @param  list<int>  $samples
 * @return array<int, int>
 */
function bucketedHistogram(array $samples): array
{
    $histogram = [];

    foreach ($samples as $sample) {
        $bucket = DurationHistogram::bucketIndex($sample);
        $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
    }

    return $histogram;
}

/** @param list<int> $samples */
function naivePercentile(array $samples, float $percentile): int
{
    sort($samples);
    $index = (int) ceil($percentile * count($samples)) - 1;

    return $samples[max(0, min(count($samples) - 1, $index))];
}

function nonNullEstimate(?int $estimate): int
{
    expect($estimate)->not->toBeNull();

    if ($estimate === null) {
        throw new RuntimeException('Expected a non-null percentile estimate.');
    }

    return $estimate;
}

it('returns null for an empty histogram', function (): void {
    expect(DurationPercentile::estimate([], 0.95))->toBeNull();
});

it('estimates the exact value for a single sample', function (): void {
    $histogram = bucketedHistogram([250]);

    expect(DurationPercentile::estimate($histogram, 0.5))
        ->toBe(DurationHistogram::bucketUpperBoundMilliseconds(DurationHistogram::bucketIndex(250)));
});

it('matches a naive sort-based percentile within one bucket width for a uniform distribution', function (): void {
    $samples = range(1, 1000);
    $histogram = bucketedHistogram($samples);

    foreach ([0.50, 0.95, 0.99] as $percentile) {
        $expected = naivePercentile($samples, $percentile);
        $estimated = nonNullEstimate(DurationPercentile::estimate($histogram, $percentile));

        expect(DurationHistogram::bucketIndex($estimated))
            ->toBe(DurationHistogram::bucketIndex($expected));
    }
});

it('places the known p99 outlier in a distribution with a long tail', function (): void {
    // 90% of samples at 50ms, 10% at 5000ms: the 990th of 1000 sorted
    // values falls within the tail, so p99 must land there too.
    $samples = array_merge(array_fill(0, 900, 50), array_fill(0, 100, 5_000));
    $histogram = bucketedHistogram($samples);

    $p50 = nonNullEstimate(DurationPercentile::estimate($histogram, 0.50));
    $p99 = nonNullEstimate(DurationPercentile::estimate($histogram, 0.99));

    expect(DurationHistogram::bucketIndex($p50))->toBe(DurationHistogram::bucketIndex(50));
    expect(DurationHistogram::bucketIndex($p99))->toBe(DurationHistogram::bucketIndex(5_000));
    expect($p99)->toBeGreaterThan($p50);
});

it('is monotonic: a higher percentile never yields a smaller estimate', function (): void {
    $samples = [10, 20, 30, 40, 400, 4_000, 40_000];
    $histogram = bucketedHistogram($samples);

    $p50 = nonNullEstimate(DurationPercentile::estimate($histogram, 0.50));
    $p95 = nonNullEstimate(DurationPercentile::estimate($histogram, 0.95));
    $p99 = nonNullEstimate(DurationPercentile::estimate($histogram, 0.99));

    expect($p95)->toBeGreaterThanOrEqual($p50);
    expect($p99)->toBeGreaterThanOrEqual($p95);
});
