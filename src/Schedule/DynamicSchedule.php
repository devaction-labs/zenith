<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final readonly class DynamicSchedule
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function available(): bool
    {
        return Schema::hasTable('zenith_dynamic_crons');
    }

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    public function create(
        string $name,
        string $expression,
        string $job,
        array $payload = [],
        ?string $timezone = null,
    ): DynamicCron {
        if (! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException("Invalid cron expression [{$expression}].");
        }

        if ($timezone !== null && ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException("Invalid timezone [{$timezone}].");
        }

        return DynamicCron::query()->create([
            'name' => $name,
            'expression' => $expression,
            'job_class' => $job,
            'payload' => $payload,
            'paused' => false,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @return list<DynamicCron>
     */
    public function events(): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_values(DynamicCron::query()->orderBy('name')->get()->all());
    }

    public function find(int $id): ?DynamicCron
    {
        if (! $this->available()) {
            return null;
        }

        return DynamicCron::query()->find($id);
    }

    public function pause(int|string $id): void
    {
        DynamicCron::query()->whereKey($id)->update(['paused' => true]);
    }

    public function resume(int|string $id): void
    {
        DynamicCron::query()->whereKey($id)->update(['paused' => false]);
    }

    public function delete(int|string $id): void
    {
        DynamicCron::query()->whereKey($id)->delete();
    }

    /**
     * Dispatch a RunDynamicCron job for every unpaused row due this minute and
     * return how many were dispatched.
     */
    public function tick(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $minute = Date::now()->startOfMinute();
        $dispatched = 0;

        foreach (DynamicCron::query()->where('paused', false)->lazyById() as $cron) {
            if (! $cron->isDueAt($minute) || ! $this->claim($cron, $minute)) {
                continue;
            }

            $this->bus->dispatch(new RunDynamicCron($cron->id));
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Claim the row for this minute with a compare-and-set on last_ran_at, so
     * only one scheduler host dispatches it when several tick at once.
     */
    private function claim(DynamicCron $cron, CarbonInterface $minute): bool
    {
        return DynamicCron::query()
            ->whereKey($cron->id)
            ->where('paused', false)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('last_ran_at')
                ->orWhere('last_ran_at', '<', $minute))
            ->update(['last_ran_at' => $minute]) === 1;
    }
}
