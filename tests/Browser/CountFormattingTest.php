<?php

declare(strict_types=1);

use function NckRtl\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;

it('formats navigation and status tab counts for compact display', function (): void {
    bindBrowserPageFixtures(
        pendingJobCount: 9_032,
        completedJobCount: 10_320,
        failedJobCount: 500,
        silencedJobCount: 100_300,
    );

    visit('/horizon/jobs/pending')
        ->waitForText('10.32K')
        ->assertSee('9,032')
        ->assertSee('10.32K')
        ->assertSee('100.3K')
        ->assertSee('120.2K')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});
