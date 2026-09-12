<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Workflows\Concerns\TransitionsConditionally;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workflow_id
 * @property string $name
 * @property string $job_class
 * @property array<string, mixed>|null $payload
 * @property list<string>|null $deps
 * @property bool $cascade
 * @property string|null $compensate_job
 * @property string $status
 * @property mixed $output
 * @property string|null $error
 * @property string|null $job_uuid
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $interrupted_at
 */
final class WorkflowStep extends Model
{
    use TransitionsConditionally;

    protected $table = 'zenith_workflow_steps';

    protected $fillable = [
        'workflow_id',
        'name',
        'job_class',
        'payload',
        'deps',
        'cascade',
        'compensate_job',
        'status',
        'output',
        'error',
        'job_uuid',
        'attempts',
        'finished_at',
        'interrupted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'deps' => 'array',
            'cascade' => 'boolean',
            'output' => 'array',
            'finished_at' => 'datetime',
            'interrupted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function isNested(): bool
    {
        return $this->job_class === Workflow::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function outputValues(): array
    {
        $output = $this->output;
        $values = [];

        if (! is_array($output)) {
            return $values;
        }

        foreach ($output as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        $deps = $this->deps ?? [];

        return array_values(array_filter($deps, is_string(...)));
    }
}
