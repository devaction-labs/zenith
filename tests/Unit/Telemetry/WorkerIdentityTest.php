<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\WorkerIdentity;

beforeEach(function (): void {
    $this->originalArgv = $_SERVER['argv'] ?? null;
});

afterEach(function (): void {
    if ($this->originalArgv === null) {
        unset($_SERVER['argv']);

        return;
    }

    $_SERVER['argv'] = $this->originalArgv;
});

it('prefers the configured node name over the local hostname', function (): void {
    config()->set('zenith.telemetry.node', 'worker-configured');

    expect(WorkerIdentity::current()->node)->toBe('worker-configured');
});

it('falls back to the local hostname when no node is configured', function (): void {
    config()->set('zenith.telemetry.node', null);

    $identity = WorkerIdentity::current();

    expect($identity->node)->not->toBe('');
});

it('reads the supervisor name horizon:work passes on the command line', function (): void {
    $_SERVER['argv'] = [
        'artisan',
        'horizon:work',
        'redis',
        '--name=default',
        '--supervisor=supervisor-1',
        '--queue=default',
    ];

    expect(WorkerIdentity::current()->supervisor)->toBe('supervisor-1');
});

it('has no supervisor when the command line carries none', function (): void {
    $_SERVER['argv'] = ['artisan', 'tinker'];

    expect(WorkerIdentity::current()->supervisor)->toBeNull();
});

it('has no supervisor when argv is unavailable', function (): void {
    unset($_SERVER['argv']);

    expect(WorkerIdentity::current()->supervisor)->toBeNull();
});
