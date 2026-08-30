<?php

declare(strict_types=1);

use function DevactionLabs\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;

it('renders mutation controls for an authorized Horizon operator', function (): void {
    bindBrowserPageFixtures();

    $pages = visit([
        '/horizon/instances',
        '/horizon/monitoring',
        '/horizon/queues',
        '/horizon/jobs/pending',
        '/horizon/failed',
    ]);

    $pages
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();

    [$instances, $monitoring, $queues, $pending, $failed] = $pages;

    $instances
        ->assertPresent('button[aria-label="Horizon instances actions"]');
    $monitoring
        ->assertPresent('button[aria-label="Monitor Tag"]')
        ->assertPresent('button[aria-label^="Monitoring actions for "]');
    $queues
        ->assertPresent('button[aria-label="Queue list actions"]')
        ->assertPresent('button[aria-label^="Queue actions for "]');
    $pending
        ->assertPresent('button[aria-label="Pending jobs actions"]');
    $failed
        ->assertPresent('button[aria-label="Failed jobs actions"]')
        ->assertPresent('button[aria-label="Retry failed job"]');
});
