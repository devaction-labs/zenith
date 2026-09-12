<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\JobAttributes;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Attributes\WithoutRelations;

#[Tries(5)]
final class TriesProbeJob
{
    public function __construct()
    {
        throw new LogicException('Zenith must never instantiate the job class.');
    }
}

#[Backoff(10, 30, 60)]
final class MultiBackoffProbeJob {}

#[Backoff(15)]
final class SingleBackoffProbeJob {}

#[Timeout(120)]
final class TimeoutProbeJob {}

#[FailOnTimeout]
final class FailOnTimeoutProbeJob {}

#[MaxExceptions(3)]
final class MaxExceptionsProbeJob {}

#[UniqueFor(300)]
final class UniqueForProbeJob {}

#[DebounceFor(30, maxWait: 120)]
final class DebounceForProbeJob {}

#[Queue('reports')]
final class QueueAttributeProbeJob {}

#[Connection('redis')]
final class ConnectionAttributeProbeJob {}

#[Delay(45)]
final class DelayProbeJob {}

#[WithoutRelations]
final class WithoutRelationsProbeJob {}

#[DeleteWhenMissingModels]
final class DeleteWhenMissingModelsProbeJob {}

final class PlainProbeJob {}

#[Tries(2)]
class BaseAttributeProbeJob {}

final class InheritedAttributeProbeJob extends BaseAttributeProbeJob {}

it('reads the tries attribute without instantiating the job class', function (): void {
    expect(JobAttributes::fromClass(TriesProbeJob::class)->tries)->toBe(5);
});

it('reads a multi-value backoff attribute as a list', function (): void {
    expect(JobAttributes::fromClass(MultiBackoffProbeJob::class)->backoff)->toBe([10, 30, 60]);
});

it('reads a single-value backoff attribute as an integer', function (): void {
    expect(JobAttributes::fromClass(SingleBackoffProbeJob::class)->backoff)->toBe(15);
});

it('reads the timeout attribute', function (): void {
    expect(JobAttributes::fromClass(TimeoutProbeJob::class)->timeout)->toBe(120);
});

it('reads the fail-on-timeout attribute as a flag', function (): void {
    expect(JobAttributes::fromClass(FailOnTimeoutProbeJob::class)->failOnTimeout)->toBeTrue()
        ->and(JobAttributes::fromClass(PlainProbeJob::class)->failOnTimeout)->toBeFalse();
});

it('reads the max exceptions attribute', function (): void {
    expect(JobAttributes::fromClass(MaxExceptionsProbeJob::class)->maxExceptions)->toBe(3);
});

it('reads the unique-for attribute', function (): void {
    expect(JobAttributes::fromClass(UniqueForProbeJob::class)->uniqueFor)->toBe(300);
});

it('reads the debounce-for attribute and its max wait', function (): void {
    $attributes = JobAttributes::fromClass(DebounceForProbeJob::class);

    expect($attributes->debounceFor)->toBe(30)
        ->and($attributes->debounceMaxWait)->toBe(120);
});

it('reads the queue attribute', function (): void {
    expect(JobAttributes::fromClass(QueueAttributeProbeJob::class)->queue)->toBe('reports');
});

it('reads the connection attribute', function (): void {
    expect(JobAttributes::fromClass(ConnectionAttributeProbeJob::class)->connection)->toBe('redis');
});

it('reads the delay attribute', function (): void {
    expect(JobAttributes::fromClass(DelayProbeJob::class)->delay)->toBe(45);
});

it('reads the without-relations attribute as a flag', function (): void {
    expect(JobAttributes::fromClass(WithoutRelationsProbeJob::class)->withoutRelations)->toBeTrue()
        ->and(JobAttributes::fromClass(PlainProbeJob::class)->withoutRelations)->toBeFalse();
});

it('reads the delete-when-missing-models attribute as a flag', function (): void {
    expect(JobAttributes::fromClass(DeleteWhenMissingModelsProbeJob::class)->deleteWhenMissingModels)
        ->toBeTrue()
        ->and(JobAttributes::fromClass(PlainProbeJob::class)->deleteWhenMissingModels)->toBeFalse();
});

it('reads attributes declared on a parent class', function (): void {
    expect(JobAttributes::fromClass(InheritedAttributeProbeJob::class)->tries)->toBe(2);
});

it('returns an empty attribute set for a job with no attributes', function (): void {
    expect(JobAttributes::fromClass(PlainProbeJob::class)->toArray())->toBe([
        'tries' => null,
        'backoff' => null,
        'timeout' => null,
        'failOnTimeout' => false,
        'maxExceptions' => null,
        'uniqueFor' => null,
        'debounceFor' => null,
        'debounceMaxWait' => null,
        'queue' => null,
        'connection' => null,
        'delay' => null,
        'withoutRelations' => false,
        'deleteWhenMissingModels' => false,
        'routedQueue' => null,
        'routedConnection' => null,
    ]);
});

it('returns an empty attribute set when the class is missing or unknown', function (): void {
    expect(JobAttributes::fromClass(null)->tries)->toBeNull()
        ->and(JobAttributes::fromClass('App\\Jobs\\DoesNotExist')->tries)->toBeNull();
});

it('resolves the Queue::route() destination for a routed job class', function (): void {
    app('queue.routes')->set(PlainProbeJob::class, 'reports', 'redis');

    $attributes = JobAttributes::fromClass(PlainProbeJob::class);

    expect($attributes->routedQueue)->toBe('reports')
        ->and($attributes->routedConnection)->toBe('redis');
});
