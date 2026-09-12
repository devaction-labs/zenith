<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $expression
 * @property string $job_class
 * @property array<string, mixed>|null $payload
 * @property bool $paused
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'paused' => 'boolean',
        ];
    }
}
