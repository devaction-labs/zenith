<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

final readonly class HorizonAbilityAuthorizer
{
    public function __construct(
        private Gate $gate,
        private AuthFactory $auth,
    ) {}

    public function allows(HorizonAbility $ability): bool
    {
        if (! $this->gate->has($ability->gate())) {
            return true;
        }

        $user = $this->auth->guard()->user();

        if ($user instanceof Authenticatable) {
            return $this->gate->forUser($user)->check($ability->gate());
        }

        return $this->gate->check($ability->gate());
    }

    public function authorize(HorizonAbility $ability): void
    {
        abort_unless($this->allows($ability), 403);
    }

    public function abilities(): HorizonAbilitiesData
    {
        return new HorizonAbilitiesData(
            pauseQueues: $this->allows(HorizonAbility::PauseQueues),
            clearQueues: $this->allows(HorizonAbility::ClearQueues),
            retryJobs: $this->allows(HorizonAbility::RetryJobs),
            cancelJobs: $this->allows(HorizonAbility::CancelJobs),
            manageInstances: $this->allows(HorizonAbility::ManageInstances),
            manageMonitoring: $this->allows(HorizonAbility::ManageMonitoring),
            manageBatches: $this->allows(HorizonAbility::ManageBatches),
        );
    }
}
