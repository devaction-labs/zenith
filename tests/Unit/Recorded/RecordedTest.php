<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Recorded\Recorded;

it('records and retrieves a job output value', function (): void {
    Recorded::record('job-1', ['ok' => true, 'count' => 3]);

    expect(Recorded::get('job-1'))->toBe(['ok' => true, 'count' => 3]);
});

it('returns null for a job that never recorded output', function (): void {
    expect(Recorded::get('missing-job'))->toBeNull();
});

it('keeps recorded output for the configured time to live', function (): void {
    config()->set('zenith.recorded.ttl', 120);

    Recorded::record('kept-job', 'value');

    $this->travel(119)->seconds();

    expect(Recorded::get('kept-job'))->toBe('value');

    $this->travel(2)->seconds();

    expect(Recorded::get('kept-job'))->toBeNull();
});

it('stores recorded output in the configured cache store', function (): void {
    config()->set('cache.stores.recorded-test', ['driver' => 'array']);
    config()->set('zenith.recorded.store', 'recorded-test');

    Recorded::record('routed-job', 'value');

    config()->set('zenith.recorded.store', null);

    expect(Recorded::get('routed-job'))->toBeNull();

    config()->set('zenith.recorded.store', 'recorded-test');

    expect(Recorded::get('routed-job'))->toBe('value');
});

it('keeps a short string recorded as-is', function (): void {
    Recorded::record('short-job', 'a short value');

    expect(Recorded::get('short-job'))->toBe('a short value');
});

it('keeps a small structured value recorded as-is', function (): void {
    Recorded::record('structured-job', ['id' => 1, 'name' => 'widget']);

    expect(Recorded::get('structured-job'))->toBe(['id' => 1, 'name' => 'widget']);
});

it('truncates output that exceeds the size cap with an explicit marker', function (): void {
    Recorded::record('big-job', str_repeat('x', 200_000));

    $stored = Recorded::get('big-job');

    expect(is_string($stored) && strlen($stored) < 200_000)->toBeTrue()
        ->and($stored)->toEndWith('...[truncated]');
});

it('truncates a large structured value into a truncated encoded string', function (): void {
    Recorded::record('big-structured-job', array_fill(0, 20_000, 'value'));

    $stored = Recorded::get('big-structured-job');

    expect($stored)->toBeString()
        ->and($stored)->toEndWith('...[truncated]');
});
