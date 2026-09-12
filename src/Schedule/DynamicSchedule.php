<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Illuminate\Support\Facades\Schema;

final class DynamicSchedule
{
    public function available(): bool
    {
        return Schema::hasTable('zenith_dynamic_crons');
    }

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $payload
     */
    public function create(string $name, string $expression, string $job, array $payload = []): DynamicCron
    {
        return DynamicCron::query()->create([
            'name' => $name,
            'expression' => $expression,
            'job_class' => $job,
            'payload' => $payload,
            'paused' => false,
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
}
