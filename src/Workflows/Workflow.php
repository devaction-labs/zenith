<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Closure;
use DevactionLabs\Zenith\Workflows\Concerns\TransitionsConditionally;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

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

    private static bool $faking = false;

    /**
     * @var list<WorkflowDefinition>
     */
    private static array $dispatched = [];

    /**
     * Fake workflow dispatch: WorkflowDefinition::dispatch() still persists the real workflow
     * and step rows, but the steps it claims are never actually queued, and every dispatched
     * WorkflowDefinition is recorded for assertDispatched(). Also fakes the bus, so
     * Bus::assertNotDispatched(RunWorkflowStep::class) works without a separate Bus::fake()
     * call. Call it again for a clean slate.
     */
    public static function fake(): void
    {
        self::$faking = true;
        self::$dispatched = [];

        Bus::fake();
    }

    public static function isFaking(): bool
    {
        return self::$faking;
    }

    public static function recordDispatch(WorkflowDefinition $definition): void
    {
        self::$dispatched[] = $definition;
    }

    /**
     * Assert that a workflow was dispatched while faked: with no argument, that any workflow
     * was dispatched; with a string, that a dispatched workflow has that name(); with a
     * closure, that some dispatched WorkflowDefinition satisfies it.
     */
    public static function assertDispatched(string|Closure|null $value = null): void
    {
        $matches = match (true) {
            $value === null => static fn (WorkflowDefinition $definition): bool => true,
            is_string($value) => static fn (WorkflowDefinition $definition): bool => $definition->name() === $value,
            default => $value,
        };

        Assert::assertTrue(
            array_any(self::$dispatched, $matches),
            'No dispatched workflow matched the given expectation.',
        );
    }

    /**
     * Stop faking workflow dispatch and forget every recorded WorkflowDefinition, so a
     * fake() from one test never carries into the next. The test suite calls this after
     * every test.
     */
    public static function stopFaking(): void
    {
        self::$faking = false;
        self::$dispatched = [];
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
