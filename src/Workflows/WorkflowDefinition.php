<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type StepDefinition array{name: string, job: class-string, payload: array<string, mixed>, deps: list<string>, cascade: bool, compensate: class-string|null, workflow: WorkflowDefinition|null}
 */
final class WorkflowDefinition
{
    /**
     * @param  list<StepDefinition>  $steps
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private ?string $name,
        private bool $unique,
        private array $context,
        private array $steps,
    ) {}

    public static function make(?string $name = null): self
    {
        return new self($name, false, [], []);
    }

    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $deps
     * @param  class-string|null  $compensate  Runs in reverse order when a later step fails.
     */
    public function add(
        string $name,
        string $job,
        array $payload = [],
        array $deps = [],
        bool $cascade = false,
        ?string $compensate = null,
    ): self {
        return $this->push([
            'name' => $name,
            'job' => $job,
            'payload' => $payload,
            'deps' => $deps,
            'cascade' => $cascade,
            'compensate' => $compensate,
            'workflow' => null,
        ]);
    }

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $deps
     * @param  class-string|null  $compensate
     */
    public function cascade(
        string $name,
        string $job,
        array $payload = [],
        array $deps = [],
        ?string $compensate = null,
    ): self {
        return $this->add($name, $job, $payload, $deps, true, $compensate);
    }

    /**
     * Add a nested workflow that runs as a single step of this one. The step completes when
     * the nested workflow completes, and the outputs of its steps cascade into dependents.
     *
     * @param  list<string>  $deps
     */
    public function addWorkflow(string $name, self $workflow, array $deps = []): self
    {
        return $this->push([
            'name' => $name,
            'job' => Workflow::class,
            'payload' => [],
            'deps' => $deps,
            'cascade' => false,
            'compensate' => null,
            'workflow' => $workflow,
        ]);
    }

    public function dispatch(): Workflow
    {
        $this->validate();

        return app(DispatchWorkflow::class)->handle($this);
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function validate(): void
    {
        $this->validateWithin([]);
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * @return array<string, mixed>
     */
    public function contextValues(): array
    {
        return $this->context;
    }

    /**
     * @return list<StepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function uniqueKey(): ?string
    {
        if (! $this->unique || $this->name === null || $this->name === '') {
            return null;
        }

        return hash('xxh3', $this->name);
    }

    /**
     * @param  StepDefinition  $step
     */
    private function push(array $step): self
    {
        if ($step['name'] === '') {
            throw new InvalidArgumentException('Workflow step names cannot be empty.');
        }

        foreach ($this->steps as $existing) {
            if ($existing['name'] === $step['name']) {
                throw new InvalidArgumentException("Workflow already has a step named [{$step['name']}].");
            }
        }

        $this->steps[] = $step;

        return $this;
    }

    /**
     * @param  list<self>  $ancestors
     */
    private function validateWithin(array $ancestors): void
    {
        if (in_array($this, $ancestors, true)) {
            throw new InvalidArgumentException(sprintf('Workflow [%s] cannot contain itself.', $this->name ?? ''));
        }

        if ($this->steps === []) {
            throw new RuntimeException('A workflow needs at least one step.');
        }

        $names = array_column($this->steps, 'name');

        foreach ($this->steps as $step) {
            foreach ($step['deps'] as $dependency) {
                if ($dependency === $step['name']) {
                    throw new InvalidArgumentException("Workflow step [{$step['name']}] cannot depend on itself.");
                }

                if (! in_array($dependency, $names, true)) {
                    throw new InvalidArgumentException(
                        "Workflow step [{$step['name']}] depends on unknown step [{$dependency}].",
                    );
                }
            }
        }

        $cycle = $this->findCycle();

        if ($cycle !== null) {
            throw new InvalidArgumentException(
                sprintf('Workflow steps form a dependency cycle: [%s].', implode(' -> ', $cycle)),
            );
        }

        foreach ($this->steps as $step) {
            $step['workflow']?->validateWithin([...$ancestors, $this]);
        }
    }

    /**
     * @return list<string>|null
     */
    private function findCycle(): ?array
    {
        $dependencies = [];

        foreach ($this->steps as $step) {
            $dependencies[$step['name']] = $step['deps'];
        }

        $explored = [];

        foreach ($this->steps as $step) {
            $cycle = $this->walk($step['name'], $dependencies, [], $explored);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $dependencies
     * @param  list<string>  $path
     * @param  array<string, true>  $explored
     * @return list<string>|null
     */
    private function walk(string $name, array $dependencies, array $path, array &$explored): ?array
    {
        $position = array_search($name, $path, true);

        if ($position !== false) {
            return [...array_slice($path, $position), $name];
        }

        if (isset($explored[$name])) {
            return null;
        }

        $path[] = $name;

        foreach ($dependencies[$name] ?? [] as $dependency) {
            $cycle = $this->walk($dependency, $dependencies, $path, $explored);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        $explored[$name] = true;

        return null;
    }
}
