<?php

declare(strict_types=1);

use DevactionLabs\Zenith\History\JobHistory;
use DevactionLabs\Zenith\History\JobHistoryPruner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/**
 * @param  array<string, mixed>  $parameters
 */
function pendingJobHistoryCommand(string $command, array $parameters = []): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException("The [{$command}] command did not return a pending command.");
    }

    return $pending;
}

function runPrunerMigration(string $command): void
{
    Artisan::call($command, [
        '--path' => dirname(__DIR__, 3).'/database/migrations/2026_08_30_030000_create_zenith_job_history_table.php',
        '--realpath' => true,
    ]);
}

/**
 * @param  array<int, string>  $tags
 */
function seedJobHistoryRow(
    string $queue,
    string $jobClass,
    string $status,
    int $ageInDays,
    array $tags = [],
): JobHistory {
    $terminalAt = now()->subDays($ageInDays);

    return JobHistory::query()->create([
        'job_class' => $jobClass,
        'queue' => $queue,
        'connection' => 'redis',
        'status' => $status,
        'attempts' => 1,
        'runtime_ms' => 10,
        'tags' => $tags,
        'error' => $status === 'failed' ? 'RuntimeException: boom' : null,
        'pushed_at' => $terminalAt,
        'completed_at' => $status === 'failed' ? null : $terminalAt,
        'failed_at' => $status === 'failed' ? $terminalAt : null,
    ]);
}

beforeEach(function (): void {
    Schema::dropIfExists('zenith_job_history');
    runPrunerMigration('migrate:refresh');
});

afterEach(function (): void {
    runPrunerMigration('migrate:reset');
});

it('deletes rows older than a wildcard rule and keeps newer rows', function (): void {
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 40);
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 1);

    $outcome = (new JobHistoryPruner)->prune([
        ['days' => 30],
    ]);

    expect($outcome->rulesApplied)->toBe(1)
        ->and($outcome->rulesSkipped)->toBe(0)
        ->and($outcome->rowsDeleted)->toBe(1)
        ->and(JobHistory::query()->count())->toBe(1);
});

it('lets an earlier specific rule protect rows a later wildcard rule would otherwise delete', function (): void {
    seedJobHistoryRow('emails', 'App\\Jobs\\SendReport', 'completed', ageInDays: 10);
    seedJobHistoryRow('reports', 'App\\Jobs\\GenerateReport', 'completed', ageInDays: 10);

    $outcome = (new JobHistoryPruner)->prune([
        ['queue' => 'emails', 'days' => 90],
        ['days' => 1],
    ]);

    expect($outcome->rowsDeleted)->toBe(1)
        ->and(JobHistory::query()->where('queue', 'emails')->count())->toBe(1)
        ->and(JobHistory::query()->where('queue', 'reports')->count())->toBe(0);
});

it('never reaches rules listed after a wildcard rule', function (): void {
    seedJobHistoryRow('emails', 'App\\Jobs\\SendReport', 'completed', ageInDays: 40);

    $outcome = (new JobHistoryPruner)->prune([
        ['days' => 30],
        ['queue' => 'emails', 'days' => 5],
    ]);

    expect($outcome->rulesApplied)->toBe(1)
        ->and($outcome->rulesSkipped)->toBe(1)
        ->and(JobHistory::query()->count())->toBe(0);
});

it('skips invalid rules without failing the whole run', function (): void {
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 40);

    $outcome = (new JobHistoryPruner)->prune([
        ['queue' => ''],
        ['days' => 30],
    ]);

    expect($outcome->rulesSkipped)->toBe(1)
        ->and($outcome->rulesApplied)->toBe(1)
        ->and($outcome->rowsDeleted)->toBe(1);
});

it('applies a status-scoped rule only to matching rows', function (): void {
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'failed', ageInDays: 40);
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 40);

    $outcome = (new JobHistoryPruner)->prune([
        ['status' => 'failed', 'days' => 7],
    ]);

    expect($outcome->rowsDeleted)->toBe(1)
        ->and(JobHistory::query()->where('status', 'completed')->count())->toBe(1);
});

it('keeps rows indefinitely when no rule matches them', function (): void {
    seedJobHistoryRow('emails', 'App\\Jobs\\SendReport', 'completed', ageInDays: 400);

    $outcome = (new JobHistoryPruner)->prune([
        ['queue' => 'reports', 'days' => 1],
    ]);

    expect($outcome->rowsDeleted)->toBe(0)
        ->and(JobHistory::query()->count())->toBe(1);
});

it('registers the prune history command with Artisan', function (): void {
    expect(Artisan::all())->toHaveKey('zenith:prune-history');
});

it('warns and succeeds when the history table is missing', function (): void {
    Schema::dropIfExists('zenith_job_history');

    $command = pendingJobHistoryCommand('zenith:prune-history');

    $command->expectsOutputToContain('The zenith_job_history table does not exist.')
        ->assertSuccessful()
        ->execute();
});

it('prunes using the configured retention rules end to end', function (): void {
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 40);
    seedJobHistoryRow('default', 'App\\Jobs\\SendReport', 'completed', ageInDays: 1);

    config(['zenith.history.retention' => [
        ['days' => 30],
    ]]);

    $command = pendingJobHistoryCommand('zenith:prune-history');

    $command->expectsOutputToContain('Pruned 1 job history row(s) using 1 retention rule(s).')
        ->assertSuccessful()
        ->execute();

    expect(JobHistory::query()->count())->toBe(1);
});
