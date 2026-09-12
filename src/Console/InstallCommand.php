<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Assets\AssetPath;
use DevactionLabs\Zenith\Assets\AssetsPublisher;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Support\ComposerAssetHook;
use DevactionLabs\Zenith\Support\ComposerAssetHookResult;
use DevactionLabs\Zenith\Support\RedisClusterDetector;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use RuntimeException;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'zenith:install
        {--force : Refresh previously published assets}
        {--no-composer-hook : Skip adding the Composer post-autoload-dump asset refresh hook}';

    protected $description = 'Publish the Zenith configuration and compiled assets';

    public function handle(
        Filesystem $filesystem,
        AssetPath $assetPath,
        AssetsPublisher $publisher,
        ComposerAssetHook $composerAssetHook,
        QueueManager $queues,
        Schedule $schedule,
        DatabaseBatchCapability $batchCapability,
    ): int {
        $force = (bool) $this->option('force');
        $packageRoot = dirname(__DIR__, 2);

        $this->publishFile(
            $filesystem,
            $packageRoot.'/config/zenith.php',
            config_path('zenith.php'),
        );

        $publisher->publish(
            destination: $assetPath->absolute(),
            force: $force,
        );

        if (! $this->option('no-composer-hook')) {
            $this->ensureComposerAssetHook($composerAssetHook);
        }

        $this->warnAboutProductionPrerequisites($queues, $schedule, $batchCapability);
        $this->components->info('Zenith is ready.');

        return self::SUCCESS;
    }

    private function ensureComposerAssetHook(ComposerAssetHook $composerAssetHook): void
    {
        $result = $composerAssetHook->ensure(base_path('composer.json'));

        match ($result) {
            ComposerAssetHookResult::Added => $this->components->info(
                'Added the Zenith asset refresh Composer hook.',
            ),
            ComposerAssetHookResult::AlreadyPresent => null,
            ComposerAssetHookResult::Missing,
            ComposerAssetHookResult::Malformed,
            ComposerAssetHookResult::Failed => $this->components->warn(
                'Could not update composer.json with the asset refresh hook. Run `php artisan zenith:assets` after Composer installs, or add `@php artisan zenith:assets --ansi` to scripts.post-autoload-dump manually.',
            ),
        };
    }

    private function warnAboutProductionPrerequisites(
        QueueManager $queues,
        Schedule $schedule,
        DatabaseBatchCapability $batchCapability,
    ): void {
        try {
            $connection = config('zenith.bulk_operations.connection');
            $queue = $queues->connection(is_string($connection) && $connection !== '' ? $connection : null);

            if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
                $this->components->warn(
                    'Bulk operations require an asynchronous queue connection processed by Horizon.',
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->components->warn(
                'The configured bulk-operation queue connection could not be verified.',
            );
        }

        $snapshotScheduled = collect($schedule->events())->contains(
            static fn (object $event): bool => str_contains(
                (string) ($event->command ?? ''),
                'horizon:snapshot',
            ),
        );

        if (! $snapshotScheduled) {
            $this->components->warn(
                'Schedule `horizon:snapshot` every five minutes to populate Horizon metrics.',
            );
        }

        $batchQueryCapability = $batchCapability->capability();

        if (! $batchQueryCapability->supported) {
            $this->components->warn(
                (string) $batchQueryCapability->message,
            );
        } elseif (! $batchQueryCapability->attributionSupported) {
            $this->components->warn(
                'Run `php artisan migrate` to enable batch queue and connection filters.',
            );
        }

        if (RedisClusterDetector::enabled()) {
            $this->components->warn(
                'Redis Cluster detected. Retained-job indexes copy source sets into hash-tagged keys to avoid CROSSSLOT commands; leave extra memory headroom for those snapshots.',
            );
        }

        if ($this->laravel->configurationIsCached() || $this->laravel->routesAreCached()) {
            $this->components->warn(
                'Laravel configuration or route caches are active; rebuild them after this installation.',
            );
        }
    }

    private function publishFile(
        Filesystem $filesystem,
        string $source,
        string $destination,
    ): void {
        if ($filesystem->exists($destination)) {
            return;
        }

        $filesystem->ensureDirectoryExists(dirname($destination));

        if (! $filesystem->copy($source, $destination)) {
            throw new RuntimeException("Unable to publish {$destination}.");
        }
    }
}
