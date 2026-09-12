<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

final class FakeQueueJob extends Job implements JobContract
{
    public function __construct(
        string $connectionName,
        string $queueName,
        private readonly string $rawBody,
        private readonly string $jobId,
        private readonly int $attemptCount = 1,
    ) {
        $this->connectionName = $connectionName;
        $this->queue = $queueName;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function attempts(): int
    {
        return $this->attemptCount;
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function fakeQueueJob(
    array $payload,
    string $connectionName = 'redis',
    string $queueName = 'default',
    string $jobId = 'job-1',
    int $attemptCount = 1,
): FakeQueueJob {
    return new FakeQueueJob(
        connectionName: $connectionName,
        queueName: $queueName,
        rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
        jobId: $jobId,
        attemptCount: $attemptCount,
    );
}
