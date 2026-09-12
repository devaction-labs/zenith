<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\DurationHistogram;

it('buckets durations on log2 boundaries', function (int $milliseconds, int $bucket): void {
    expect(DurationHistogram::bucketIndex($milliseconds))->toBe($bucket);
})->with([
    'zero' => [0, 0],
    'one millisecond' => [1, 0],
    'two milliseconds' => [2, 1],
    'three milliseconds' => [3, 1],
    'just under 1024ms' => [1023, 9],
    'exactly 1024ms' => [1024, 10],
    'negative is clamped to zero' => [-5, 0],
]);

it('clamps very large durations into the final overflow bucket', function (): void {
    $overflow = DurationHistogram::BUCKET_COUNT - 1;

    expect(DurationHistogram::bucketIndex(2 ** 30))->toBe($overflow);
});

it('reports a monotonically increasing upper bound per bucket', function (): void {
    $previous = -1;

    for ($index = 0; $index < DurationHistogram::BUCKET_COUNT; $index++) {
        $upperBound = DurationHistogram::bucketUpperBoundMilliseconds($index);

        expect($upperBound)->toBeGreaterThan($previous);

        $previous = $upperBound;
    }
});

it('keeps bucket index and upper bound consistent with each other', function (): void {
    foreach ([0, 1, 5, 100, 1023, 1024, 5000] as $milliseconds) {
        $bucket = DurationHistogram::bucketIndex($milliseconds);

        expect(DurationHistogram::bucketUpperBoundMilliseconds($bucket))->toBeGreaterThanOrEqual($milliseconds);
    }
});
