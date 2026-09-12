<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests\Support;

use Closure;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use Throwable;

/**
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T&MockInterface
 */
function mockDashboardContract(string $class): object
{
    $mock = Mockery::mock($class);

    if (! $mock instanceof $class) {
        throw new LogicException("Mock does not implement {$class}.");
    }

    return $mock;
}

function dashboardReturns(MockInterface $mock, string $method, mixed $value): void
{
    $mock->shouldReceive($method)->andReturn($value);
}

function dashboardReturnsUsing(MockInterface $mock, string $method, Closure $return): void
{
    $mock->shouldReceive($method)->andReturnUsing($return);
}

/**
 * @param  array<int, mixed>  $arguments
 * @param  'never'|'once'|'twice'|'zeroOrMoreTimes'  $times
 */
function dashboardExpects(
    MockInterface $mock,
    string $method,
    array $arguments = [],
    string $times = 'once',
    mixed $value = null,
    ?Closure $returnUsing = null,
    ?Throwable $exception = null,
    bool $ordered = false,
): void {
    $expectation = $mock->shouldReceive($method);

    if ($arguments !== []) {
        $expectation->with(...$arguments);
    }

    $expectation->{$times}();

    if ($ordered) {
        $expectation->ordered();
    }

    if ($returnUsing instanceof Closure) {
        $expectation->andReturnUsing($returnUsing);

        return;
    }

    if ($exception instanceof Throwable) {
        $expectation->andThrow($exception);

        return;
    }

    $expectation->andReturn($value);
}

/** @param array<int, mixed> $arguments */
function dashboardReturnsFor(
    MockInterface $mock,
    string $method,
    array $arguments,
    mixed $value,
): void {
    $mock->shouldReceive($method)->with(...$arguments)->once()->andReturn($value);
}

function dashboardThrows(MockInterface $mock, string $method, Throwable $exception): void
{
    $mock->shouldReceive($method)->andThrow($exception);
}

function dashboardNeverReceives(MockInterface $mock, string $method): void
{
    $mock->shouldReceive($method)->never();
}

/** @param array<int, mixed> $arguments */
function dashboardThrowsFor(
    MockInterface $mock,
    string $method,
    array $arguments,
    Throwable $exception,
): void {
    $mock->shouldReceive($method)->with(...$arguments)->once()->andThrow($exception);
}
