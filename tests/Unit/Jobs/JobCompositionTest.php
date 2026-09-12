<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\JobComposition;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Tests\Support\HorizonJob;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

final class UniqueEncryptedProbeJob implements ShouldBeEncrypted, ShouldBeUnique {}

final class DownstreamProbeJob {}

final class ChainedProbeJob
{
    /** @param list<string> $chained */
    public function __construct(
        public array $chained = [],
    ) {}
}

it('reads unique, encrypted, and chained contracts from a retained payload', function (): void {
    $command = new ChainedProbeJob([serialize(new DownstreamProbeJob)]);
    $job = chainedHorizonJob($command, UniqueEncryptedProbeJob::class);

    $detail = (new JobsData(mockDashboardContract(JobRepository::class)))->detail($job);

    expect($detail)->not->toBeNull()
        ->and($detail?->composition->unique)->toBeTrue()
        ->and($detail?->composition->encrypted)->toBeTrue()
        ->and($detail?->composition->chain)->toHaveCount(1)
        ->and($detail?->composition->chain[0]->class)->toBe(DownstreamProbeJob::class);
});

it('returns empty composition when the payload has no command', function (): void {
    $composition = JobComposition::fromPayload([
        'data' => ['commandName' => 'App\\Jobs\\Missing'],
    ], null);

    expect($composition->toArray())->toBe([
        'unique' => false,
        'encrypted' => false,
        'chain' => [],
    ]);
});

function chainedHorizonJob(object $command, string $commandName): HorizonJob
{
    $job = horizonJob(0, 'job-chain');
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        throw new LogicException('The Horizon job payload must decode to an array.');
    }

    data_set($payload, 'displayName', $commandName);
    data_set($payload, 'data.commandName', $commandName);
    data_set($payload, 'data.command', serialize($command));
    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    $job->name = $commandName;

    return $job;
}
