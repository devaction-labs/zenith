<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\SchedulePauseStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::store()->forget('illuminate:schedule:paused');
});

it('reports the scheduler as running by default', function (): void {
    expect(app(SchedulePauseStatus::class)->paused())->toBeFalse();
});

it('reports the scheduler as paused after schedule:pause runs', function (): void {
    Artisan::call('schedule:pause');

    expect(app(SchedulePauseStatus::class)->paused())->toBeTrue();
});

it('reports the scheduler as running again after schedule:resume runs', function (): void {
    Artisan::call('schedule:pause');
    Artisan::call('schedule:resume');

    expect(app(SchedulePauseStatus::class)->paused())->toBeFalse();
});
