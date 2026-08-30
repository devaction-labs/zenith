<?php

declare(strict_types=1);

use function DevactionLabs\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;

it('renders numeric durations with compact unit suffixes', function (): void {
    bindBrowserPageFixtures();
    config()->set('queue.connections.redis.retry_after', 5_552);

    $pages = visit([
        '/horizon/jobs/completed/completed-1',
        '/horizon/supervisors/horizon-web-01%3Asupervisor-1',
        '/horizon/failed',
        '/horizon/metrics/jobs/App%5CJobs%5CImportFeed',
    ]);

    $pages
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();

    [$completedJob, $supervisor, $failedJobs, $jobMetric] = $pages;

    $completedJob
        ->assertSee('1.5s')
        ->assertDontSee('1.5 seconds');

    $supervisor
        ->assertSee('1h 32m 32s')
        ->assertDontSee('1 hour, 32 minutes, 32 seconds');

    $failedJobs
        ->assertSee('100ms')
        ->assertDontSee('0.1s');

    $jobMetric
        ->assertSee('Latest runtime: 100ms.')
        ->assertDontSee('Latest runtime: 0.1 seconds.');
});
