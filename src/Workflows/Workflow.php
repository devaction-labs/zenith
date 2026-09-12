<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Workflows\Concerns\TransitionsConditionally;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
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

    public function cancel(): void
    {
        if ($this->status->finished()) {
            return;
        }

        $this->forceFill([
            'status' => WorkflowStatus::Cancelled,
            'finished_at' => Date::now(),
        ])->save();

        $this->steps()
            ->whereNotIn('status', [
                WorkflowStatus::Completed->value,
                WorkflowStatus::Failed->value,
                WorkflowStatus::Cancelled->value,
            ])
            ->update([
                'status' => WorkflowStatus::Cancelled->value,
                'finished_at' => Date::now(),
            ]);
    }

    public function retryFrom(string $step): void
    {
        app(AdvanceWorkflow::class)->retry($this, $step);
    }
}
