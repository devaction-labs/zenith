<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Cron\CronExpression;
use DateInvalidTimeZoneException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property int $id
 * @property string $name
 * @property string $expression
 * @property string $job_class
 * @property array<string, mixed>|null $payload
 * @property bool $paused
 * @property string|null $timezone
 * @property Carbon|null $last_ran_at
 */
final class DynamicCron extends Model
{
    protected $table = 'zenith_dynamic_crons';

    protected $fillable = [
        'name',
        'expression',
        'job_class',
        'payload',
        'paused',
        'timezone',
    ];

    /**
     * Get the timezone the expression runs in, falling back to the scheduler timezone.
     */
    public function effectiveTimezone(): string
    {
        if ($this->timezone !== null && $this->timezone !== '') {
            return $this->timezone;
        }

        $timezone = config('app.schedule_timezone') ?? config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * Determine whether the expression is due at the given moment. A row with an
     * invalid expression or timezone is never due.
     */
    public function isDueAt(DateTimeInterface $moment): bool
    {
        try {
            return (new CronExpression($this->expression))->isDue($moment, $this->effectiveTimezone());
        } catch (InvalidArgumentException|DateInvalidTimeZoneException) {
            return false;
        }
    }

    public function nextRunDate(DateTimeInterface $after): ?DateTimeInterface
    {
        try {
            return (new CronExpression($this->expression))
                ->getNextRunDate($after, 0, false, $this->effectiveTimezone());
        } catch (InvalidArgumentException|RuntimeException|DateInvalidTimeZoneException) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'paused' => 'boolean',
            'last_ran_at' => 'datetime',
        ];
    }
}
