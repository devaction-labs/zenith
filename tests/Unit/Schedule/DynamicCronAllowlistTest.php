<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\DynamicCronAllowlist;
use InvalidArgumentException;

it('allows nothing by default', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', []);

    $allowlist = app(DynamicCronAllowlist::class);

    expect($allowlist->allowed())->toBe([])
        ->and($allowlist->allows(FetchWorkflowStep::class))->toBeFalse();
});

it('allows exactly the configured classes', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', [FetchWorkflowStep::class]);

    $allowlist = app(DynamicCronAllowlist::class);

    expect($allowlist->allows(FetchWorkflowStep::class))->toBeTrue()
        ->and($allowlist->allows(ProcessWorkflowStep::class))->toBeFalse();
});

it('ignores non-string and non-existent entries in a misconfigured allowlist', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', [
        FetchWorkflowStep::class,
        42,
        null,
        'App\\Jobs\\DoesNotExist',
    ]);

    expect(app(DynamicCronAllowlist::class)->allowed())->toBe([FetchWorkflowStep::class]);
});

it('throws for a class outside the allowlist', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', [FetchWorkflowStep::class]);

    app(DynamicCronAllowlist::class)->ensureAllowed(ProcessWorkflowStep::class);
})->throws(InvalidArgumentException::class);

it('does not throw for a class inside the allowlist', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', [FetchWorkflowStep::class]);

    app(DynamicCronAllowlist::class)->ensureAllowed(FetchWorkflowStep::class);
})->throwsNoExceptions();
