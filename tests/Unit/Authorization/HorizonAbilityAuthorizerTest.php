<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbility;
use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbilityAuthorizer;
use Illuminate\Support\Facades\Gate;

describe('HorizonAbilityAuthorizer', function (): void {
    it('allows every mutation when no ability gates are defined', function (): void {
        $authorizer = app(HorizonAbilityAuthorizer::class);

        expect($authorizer->allows(HorizonAbility::PauseQueues))->toBeTrue()
            ->and($authorizer->abilities()->pauseQueues)->toBeTrue();
    });

    it('denies a mutation when its dedicated gate rejects the operator', function (): void {
        Gate::define('horizon-new-dawn.pauseQueues', static fn (): bool => false);

        $authorizer = app(HorizonAbilityAuthorizer::class);

        expect($authorizer->allows(HorizonAbility::PauseQueues))->toBeFalse()
            ->and($authorizer->allows(HorizonAbility::RetryJobs))->toBeTrue();
    });
});
