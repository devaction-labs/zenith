<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Actions;

use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobRetryEligibility;
use DevactionLabs\Zenith\Jobs\RetainedJobRetryResult;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Str;
use JsonException;

final readonly class RetryRetainedJob
{
    public function __construct(
        private JobsData $jobs,
        private QueueFactory $queue,
        private ?RetainedJobRetryEligibility $eligibility = null,
    ) {}

    /**
     * Re-dispatch a retained completed or silenced job's payload as a
     * brand-new job, respecting ShouldBeUnique and debounce contracts.
     */
    public function handle(string $id): RetainedJobRetryResult
    {
        $job = $this->jobs->retainedCompletedJob($id);

        if ($job === null) {
            return RetainedJobRetryResult::NotRetained;
        }

        if ($job->commandClass === null || ! class_exists($job->commandClass)) {
            return RetainedJobRetryResult::ClassMissing;
        }

        if (! ($this->eligibility ?? new RetainedJobRetryEligibility)->allows($job->commandClass)) {
            return RetainedJobRetryResult::UniqueOrDebounced;
        }

        try {
            $prepared = $this->preparePayload($job->rawPayload, $id);
        } catch (JsonException) {
            return RetainedJobRetryResult::NotRetained;
        }

        $this->queue->connection($job->connection)->pushRaw($prepared, $job->queue);

        return RetainedJobRetryResult::Retried;
    }

    /**
     * @throws JsonException
     */
    private function preparePayload(string $rawPayload, string $retryOf): string
    {
        $decoded = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('The retained job payload did not decode to an array.');
        }

        $newId = (string) Str::uuid();

        return json_encode(array_merge($decoded, [
            'id' => $newId,
            'uuid' => $newId,
            'attempts' => 0,
            'retry_of' => $retryOf,
        ]), JSON_THROW_ON_ERROR);
    }
}
