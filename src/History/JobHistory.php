<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $job_class
 * @property string $queue
 * @property string $connection
 * @property JobHistoryStatus $status
 * @property int $attempts
 * @property int|null $runtime_ms
 * @property array<int, string>|null $tags
 * @property string|null $error
 * @property Carbon|null $pushed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 */
final class JobHistory extends Model
{
    public $timestamps = false;

    protected $table = JobHistoryRecorder::TABLE;

    protected $fillable = [
        'job_class',
        'queue',
        'connection',
        'status',
        'attempts',
        'runtime_ms',
        'tags',
        'error',
        'pushed_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobHistoryStatus::class,
            'tags' => 'array',
            'pushed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
