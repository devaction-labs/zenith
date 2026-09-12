<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

use Closure;
use DevactionLabs\Zenith\Batches\BatchRepositoryOverview;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Queues\QueuesData;
use DevactionLabs\Zenith\Support\Data\NavigationCountsData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final readonly class NavigationCounts
{
    public function __construct(
        private RedisFactory $redis,
        private JobRepository $jobs,
        private BatchRepositoryOverview $batches,
        private QueuesData $queues,
        private MasterSupervisorRepository $masters,
        private DatabaseBatchCapability $batchCapability,
    ) {}

    public function get(): NavigationCountsData
    {
        return new NavigationCountsData(
            instances: $this->safely(fn (): int => count($this->masters->all())),
            monitoring: $this->safely(fn (): int => $this->setCount('monitoring')),
            metrics: $this->safely(fn (): int => $this->setCount('measured_jobs') + $this->setCount('measured_queues')),
            queues: $this->safely($this->queues->count(...)),
            batches: $this->safely($this->batchCount(...)),
            pending: $this->safely(fn (): int => $this->jobs->countPending()),
            completed: $this->safely(fn (): int => $this->jobs->countCompleted()),
            silenced: $this->safely(fn (): int => $this->jobs->countSilenced()),
            failed: $this->safely(fn (): int => $this->jobs->countFailed()),
        );
    }

    private function setCount(string $key): int
    {
        return (int) $this->redis->connection('horizon')->scard($key);
    }

    private function batchCount(): ?int
    {
        if (! $this->batchCapability->available()) {
            return null;
        }

        $overview = $this->batches->get();

        return $overview['complete'] ? $overview['total'] : null;
    }

    private function safely(Closure $count): ?int
    {
        try {
            $resolved = $count();

            return $resolved === null ? null : (int) $resolved;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
