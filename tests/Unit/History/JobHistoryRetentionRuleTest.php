<?php

declare(strict_types=1);

use DevactionLabs\Zenith\History\JobHistory;
use DevactionLabs\Zenith\History\JobHistoryRetentionRule;
use DevactionLabs\Zenith\History\JobHistoryStatus;

/**
 * @param  array<array-key, mixed>  $rule
 */
function validJobHistoryRetentionRule(array $rule): JobHistoryRetentionRule
{
    $resolved = JobHistoryRetentionRule::tryFromArray($rule);

    if (! $resolved instanceof JobHistoryRetentionRule) {
        throw new RuntimeException('Expected a valid retention rule.');
    }

    return $resolved;
}

it('rejects a rule without a valid days value', function (): void {
    expect(JobHistoryRetentionRule::tryFromArray(['queue' => 'emails']))->toBeNull();
    expect(JobHistoryRetentionRule::tryFromArray(['days' => 0]))->toBeNull();
    expect(JobHistoryRetentionRule::tryFromArray(['days' => 'seven']))->toBeNull();
});

it('rejects a rule with an empty queue or class filter', function (): void {
    expect(JobHistoryRetentionRule::tryFromArray(['days' => 7, 'queue' => '']))->toBeNull();
    expect(JobHistoryRetentionRule::tryFromArray(['days' => 7, 'class' => '']))->toBeNull();
});

it('rejects a rule with an unknown status value', function (): void {
    expect(JobHistoryRetentionRule::tryFromArray(['days' => 7, 'status' => 'archived']))->toBeNull();
});

it('accepts a wildcard rule with only a days value', function (): void {
    $rule = validJobHistoryRetentionRule(['days' => 30]);

    expect($rule->isWildcard())->toBeTrue()
        ->and($rule->days)->toBe(30);
});

it('accepts a fully scoped rule', function (): void {
    $rule = validJobHistoryRetentionRule([
        'queue' => 'emails',
        'class' => 'App\\Jobs\\SendReport',
        'status' => 'failed',
        'days' => 14,
    ]);

    expect($rule->isWildcard())->toBeFalse()
        ->and($rule->queue)->toBe('emails')
        ->and($rule->jobClass)->toBe('App\\Jobs\\SendReport')
        ->and($rule->status)->toBe(JobHistoryStatus::Failed)
        ->and($rule->days)->toBe(14);
});

it('scopes a query to its own filters', function (): void {
    $rule = validJobHistoryRetentionRule(['queue' => 'emails', 'status' => 'failed', 'days' => 7]);
    $query = JobHistory::query();

    $rule->scopeTo($query);

    expect($query->toSql())
        ->toContain('"queue" = ?')
        ->toContain('"status" = ?');
});

it('excludes rows matching an earlier rule from a later query', function (): void {
    $rule = validJobHistoryRetentionRule(['queue' => 'emails', 'days' => 7]);
    $query = JobHistory::query();

    $rule->excludeFrom($query);

    expect($query->toSql())->toContain('"queue" != ?');
});
