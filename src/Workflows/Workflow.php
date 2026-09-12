<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Workflows\Concerns\TransitionsConditionally;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $unique_key
 * @property WorkflowStatus $status
 * @property string|null $parent_id
 * @property string|null $parent_step
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $finished_at
 */
final class Workflow extends Model
{
    use TransitionsConditionally;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'zenith_workflows';

    protected $fillable = [
        'id',
        'name',
        'unique_key',
        'status',
        'parent_id',
        'parent_step',
        'context',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'context' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $workflow): void {
            $workflow->id ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'workflow_id')->orderBy('id');
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function cancel(): void
    {
        app(AdvanceWorkflow::class)->cancel($this);
    }

    public function retryFrom(?string $step = null): void
    {
        app(AdvanceWorkflow::class)->retry($this, $step);
    }
}
