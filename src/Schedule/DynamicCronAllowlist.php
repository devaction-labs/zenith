<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use InvalidArgumentException;

/**
 * The configured allowlist of job classes a dynamic cron may dispatch.
 *
 * Empty by default, so the dashboard can never dispatch an arbitrary class
 * until an operator explicitly opts a job class in through
 * `zenith.dynamic_cron_allowed_classes`.
 */
final readonly class DynamicCronAllowlist
{
    /**
     * @return list<class-string>
     */
    public function allowed(): array
    {
        $configured = config('zenith.dynamic_cron_allowed_classes', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            $configured,
            static fn (mixed $class): bool => is_string($class) && $class !== '' && class_exists($class),
        ));
    }

    public function allows(string $class): bool
    {
        return in_array($class, $this->allowed(), true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function ensureAllowed(string $class): void
    {
        if (! $this->allows($class)) {
            throw new InvalidArgumentException("Job class [{$class}] is not in the configured dynamic cron allowlist.");
        }
    }
}
