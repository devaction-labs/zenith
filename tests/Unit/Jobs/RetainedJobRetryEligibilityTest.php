<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\RetainedJobRetryEligibility;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\DebounceFor;

final class RetryEligibleProbeJob {}

final class UniqueRetryProbeJob implements ShouldBeUnique {}

#[DebounceFor(60)]
final class DebouncedRetryProbeJob {}

it('allows retrying an ordinary retained job class', function (): void {
    expect((new RetainedJobRetryEligibility)->allows(RetryEligibleProbeJob::class))->toBeTrue();
});

it('refuses a missing or unresolved command class', function (): void {
    $eligibility = new RetainedJobRetryEligibility;

    expect($eligibility->allows(null))->toBeFalse()
        ->and($eligibility->allows('App\\Jobs\\DoesNotExist'))->toBeFalse();
});

it('refuses a job class that enforces uniqueness', function (): void {
    expect((new RetainedJobRetryEligibility)->allows(UniqueRetryProbeJob::class))->toBeFalse();
});

it('refuses a job class that declares a debounce contract', function (): void {
    expect((new RetainedJobRetryEligibility)->allows(DebouncedRetryProbeJob::class))->toBeFalse();
});
