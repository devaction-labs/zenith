<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Supervisors\Actions;

use DevactionLabs\Zenith\Supervisors\LocalSupervisor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\SupervisorCommands\Scale;
use RuntimeException;

final readonly class ScaleSupervisor
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private HorizonCommandQueue $commands,
        private ConfigRepository $config,
    ) {}

    public function handle(string $supervisor, int $processes): void
    {
        $record = $this->findSupervisor($supervisor);

        if (! is_object($record) || ! LocalSupervisor::matches($record, $supervisor)) {
            throw new RuntimeException('The requested Horizon supervisor is not active.');
        }

        [$min, $max] = $this->bounds($record);

        if ($processes < $min || $processes > $max) {
            throw new InvalidArgumentException(
                "The process count must be between {$min} and {$max}.",
            );
        }

        $this->commands->push($supervisor, Scale::class, ['scale' => $processes]);
    }

    private function findSupervisor(string $supervisor): mixed
    {
        return $this->supervisors->find($supervisor);
    }

    /** @return array{0: int, 1: int} */
    private function bounds(object $record): array
    {
        $options = is_array($record->options ?? null) ? $record->options : [];

        $min = $this->intOption($options, 'minProcesses')
            ?? $this->configuredBound('min', 1);

        $max = $this->intOption($options, 'maxProcesses')
            ?? $this->configuredBound('max', 20);

        return [$min, $max];
    }

    /** @param array<array-key, mixed> $options */
    private function intOption(array $options, string $key): ?int
    {
        $value = $options[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function configuredBound(string $key, int $default): int
    {
        $value = $this->config->get("zenith.supervisor_scale_bounds.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
