<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/RetainedJobBrowserFixtures.php';

use DevactionLabs\Zenith\Jobs\RetainedJobType;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserFailedJobBulkLimitFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserFailedJobIdentifierOverflowFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserProcessTransitionFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserSupervisorScalingFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindRetainedJobBrowserFixtures;

describe('Horizon interface interactions', function (): void {
    beforeEach(function (): void {
        bindBrowserPageFixtures();
    });

    it('offers scoped bulk job actions without duplicating filter controls', function (): void {
        visit('/horizon/jobs/pending')
            ->click('button[aria-label="Pending jobs actions"]')
            ->assertSee('Cancel all ready jobs')
            ->assertSee('Cancel all delayed jobs')
            ->assertSee('Cancel all pending jobs')
            ->assertDontSee('Clear all filters')
            ->click('Cancel all delayed jobs')
            ->assertSee('Cancel all delayed jobs?')
            ->assertSee('Ready, reserved, and running jobs will not be affected.')
            ->click('[data-test="confirm-cancel-pending-jobs"]')
            ->assertSee('Cancelling delayed jobs was queued.');

        visit('/horizon/failed')
            ->click('button[aria-label="Failed jobs actions"]')
            ->assertSee('Clear all failed jobs')
            ->assertDontSee('Clear all filters');

        visit('/horizon/jobs/completed')
            ->assertMissing('button[aria-label="Completed jobs actions"]')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps global failed-job actions available for large retained sets', function (): void {
        bindBrowserFailedJobBulkLimitFixtures();

        $page = visit('/horizon/failed')
            ->click('button[aria-label="Failed jobs actions"]')
            ->assertSee('Retry all')
            ->assertSee('Clear all failed jobs')
            ->assertDontSee('exceed the configured limit')
            ->assertMissing('[data-test="retry-all-failed-jobs"][aria-disabled="true"]')
            ->assertMissing('[data-test="clear-all-failed-jobs"][aria-disabled="true"]');

        // Axe color-contrast samples painted pixels; wait until the open-menu
        // fade finishes so semi-transparent frames are not measured.
        $page->script(<<<'JS'
            () => new Promise((resolve) => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        window.setTimeout(resolve, 300)
                    })
                })
            })
        JS);

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });

    it('cancels a pending job before a worker reserves it', function (): void {
        visit('/horizon/jobs/pending/pending-1')
            ->click('button[aria-label="Pending job actions"]')
            ->click('Cancel job')
            ->assertSee('Cancel this job?')
            ->click('[data-test="confirm-cancel-job"]')
            ->assertPathIs('/horizon/jobs/pending')
            ->assertSee('Job cancelled.')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('preserves interface preferences across Inertia navigation', function (): void {
        $page = visit('/horizon');

        $page
            ->click('[aria-label^="Color scheme:"]')
            ->assertScript('document.documentElement.classList.contains("dark")')
            ->click('[aria-label="Auto load new entries"]')
            ->assertAttribute('[aria-label="Auto load new entries"]', 'aria-pressed', 'true')
            ->click('Monitoring')
            ->assertPathIs('/horizon/monitoring')
            ->assertAttribute('[aria-label="Auto load new entries"]', 'aria-pressed', 'true')
            ->assertScript('document.documentElement.classList.contains("dark")')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('prefetches queue metrics without showing a deferred chart fallback', function (): void {
        $page = visit('/horizon/queues/reports')
            ->waitForText('Retained Pending Jobs');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                const headers = new WeakMap()
                const partialRequests = []
                const originalSend = XMLHttpRequest.prototype.send
                const originalSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                let fallbackSeen = false
                const observer = new MutationObserver(inspect)
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out switching to the prefetched queue metrics view.')),
                    10000,
                )

                function inspect() {
                    fallbackSeen ||= document.querySelector(
                        '[aria-label="Loading queue metrics"]',
                    ) !== null
                }

                function finish(error = null) {
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)
                    observer.disconnect()
                    XMLHttpRequest.prototype.send = originalSend
                    XMLHttpRequest.prototype.setRequestHeader = originalSetRequestHeader

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve({
                        fallbackSeen,
                        partialRequests,
                        search: window.location.search,
                        headings: Array.from(document.querySelectorAll('h2'))
                            .map((heading) => heading.textContent?.trim()),
                    })
                }

                function onSuccess(event) {
                    if (
                        event.detail.page.component !== 'Queues/Show'
                        || event.detail.page.props.view !== 'metrics'
                        || ! window.location.search.includes('view=metrics')
                    ) {
                        return
                    }

                    window.requestAnimationFrame(() => window.requestAnimationFrame(() => finish()))
                }

                XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                    const requestHeaders = headers.get(this) ?? {}
                    requestHeaders[name.toLowerCase()] = String(value)
                    headers.set(this, requestHeaders)

                    return originalSetRequestHeader.call(this, name, value)
                }

                XMLHttpRequest.prototype.send = function (body) {
                    const partialData = headers.get(this)?.['x-inertia-partial-data']

                    if (partialData) {
                        partialRequests.push(partialData)
                    }

                    return originalSend.call(this, body)
                }

                const metrics = Array.from(document.querySelectorAll('[role="tab"]'))
                    .find((tab) => tab.textContent?.trim() === 'Metrics')

                if (!(metrics instanceof HTMLElement)) {
                    finish(new Error('The Metrics queue tab was not found.'))
                    return
                }

                inspect()
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                })
                document.addEventListener('inertia:success', onSuccess)
                metrics.dispatchEvent(new MouseEvent('mouseover', {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                }))
                window.setTimeout(() => metrics.click(), 150)
            })
        JS);

        expect($result['fallbackSeen'])->toBeFalse()
            ->and($result['partialRequests'])->toContain('view,preview')
            ->and($result['search'])->toBe('?tab=pending&view=metrics')
            ->and($result['headings'])->toContain('Throughput — reports')
            ->and($result['headings'])->toContain('Runtime — reports');

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps long failed-job identifiers inside directional mobile scrollers', function (): void {
        [
            'failedJobId' => $failedJobId,
            'batchId' => $batchId,
        ] = bindBrowserFailedJobIdentifierOverflowFixtures();

        $page = visit("/horizon/failed/{$failedJobId}")
            ->on()->mobile()
            ->resize(337, 844)
            ->assertSee($failedJobId)
            ->assertSee($batchId)
            ->assertPresent('[data-test="failed-job-id"]')
            ->assertPresent('[data-test="failed-job-batch-id"]');

        $identifierOverflow = $page->script(<<<'JS'
            async () => {
                const nextFrame = () => new Promise((resolve) => requestAnimationFrame(resolve))
                const waitForState = async (wrapper, left, right) => {
                    for (let frame = 0; frame < 120; frame++) {
                        const state = {
                            left: wrapper.getAttribute('data-overflow-left') === 'true',
                            right: wrapper.getAttribute('data-overflow-right') === 'true',
                        }

                        if (state.left === left && state.right === right) {
                            return state
                        }

                        await nextFrame()
                    }

                    throw new Error(`Timed out waiting for overflow state ${left}/${right}.`)
                }
                const inspect = async (selector) => {
                    const valueCell = document.querySelector(selector)
                    const scroll = valueCell?.querySelector('[data-slot="detail-list-value-scroll"]')
                    const overflowWrapper = scroll?.closest(
                        '[data-overflow-left][data-overflow-right]',
                    )
                    const fadeLeft = valueCell?.querySelector(
                        '[data-slot="detail-list-value-fade-left"]',
                    )
                    const fadeRight = valueCell?.querySelector(
                        '[data-slot="detail-list-value-fade-right"]',
                    )

                    if (
                        ! valueCell
                        || ! scroll
                        || ! overflowWrapper
                        || ! fadeLeft
                        || ! fadeRight
                    ) {
                        throw new Error(`Missing overflow elements for ${selector}.`)
                    }

                    const scrollStyle = getComputedStyle(scroll)
                    const scrollbarStyle = getComputedStyle(scroll, '::-webkit-scrollbar')
                    const fadeLeftStyle = getComputedStyle(fadeLeft)
                    const fadeRightStyle = getComputedStyle(fadeRight)
                    const initial = await waitForState(overflowWrapper, false, true)
                    const maxScrollLeft = scroll.scrollWidth - scroll.clientWidth

                    scroll.scrollLeft = maxScrollLeft / 2
                    scroll.dispatchEvent(new Event('scroll'))
                    const middle = await waitForState(overflowWrapper, true, true)

                    scroll.scrollLeft = maxScrollLeft
                    scroll.dispatchEvent(new Event('scroll'))
                    const end = await waitForState(overflowWrapper, true, false)

                    scroll.scrollLeft = 0
                    scroll.dispatchEvent(new Event('scroll'))
                    const backAtStart = await waitForState(overflowWrapper, false, true)

                    return {
                        whiteSpace: scrollStyle.whiteSpace,
                        overflowX: scrollStyle.overflowX,
                        scrollbarWidth: scrollStyle.scrollbarWidth,
                        webkitScrollbarDisplay: scrollbarStyle.display,
                        clientWidth: scroll.clientWidth,
                        scrollWidth: scroll.scrollWidth,
                        fadeWidths: [
                            fadeLeft.getBoundingClientRect().width,
                            fadeRight.getBoundingClientRect().width,
                        ],
                        fadePointerEvents: [
                            fadeLeftStyle.pointerEvents,
                            fadeRightStyle.pointerEvents,
                        ],
                        states: {
                            initial,
                            middle,
                            end,
                            backAtStart,
                        },
                    }
                }

                return {
                    viewportWidth: window.innerWidth,
                    documentHasHorizontalOverflow:
                        document.documentElement.scrollWidth
                        > document.documentElement.clientWidth,
                    failedJobId: await inspect('[data-test="failed-job-id"]'),
                    batchId: await inspect('[data-test="failed-job-batch-id"]'),
                }
            }
        JS);

        expect($identifierOverflow)->toMatchArray([
            'viewportWidth' => 337,
            'documentHasHorizontalOverflow' => false,
        ]);

        foreach (['failedJobId', 'batchId'] as $identifier) {
            $overflow = $identifierOverflow[$identifier];

            expect($overflow)->toMatchArray([
                'whiteSpace' => 'nowrap',
                'overflowX' => 'auto',
                'scrollbarWidth' => 'none',
                'webkitScrollbarDisplay' => 'none',
                'fadePointerEvents' => ['none', 'none'],
                'states' => [
                    'initial' => ['left' => false, 'right' => true],
                    'middle' => ['left' => true, 'right' => true],
                    'end' => ['left' => true, 'right' => false],
                    'backAtStart' => ['left' => false, 'right' => true],
                ],
            ]);
            expect($overflow['scrollWidth'])->toBeGreaterThan($overflow['clientWidth']);
            expect($overflow['fadeWidths'])->each->toBe(40);
        }

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });

    it('keeps predicted autoscaling active until the process count reaches its target', function (): void {
        bindBrowserSupervisorScalingFixtures();

        $page = visit('/horizon/instances')->assertSee('Instances');
        $autoRefreshWasEnabled = $page->script(
            '() => localStorage.getItem("horizonAutoLoadsNewEntries") === "1"',
        );

        if (! $autoRefreshWasEnabled) {
            $page->script(
                '() => localStorage.setItem("horizonAutoLoadsNewEntries", "1")',
            );
            $page->refresh();
        }

        $page
            ->assertAttribute('[aria-label="Auto load new entries"]', 'aria-pressed', 'true')
            ->assertPresent('[data-scaling-state="up"]');

        $meters = $page->script(<<<'JS'
            () => {
                const meters = {}

                for (const state of ['up']) {
                    const meter = document.querySelector(`[data-scaling-state="${state}"]`)
                    const cell = meter?.closest('td')
                    const track = meter?.querySelector('[data-scaling-track]')
                    const processCount = cell?.querySelector('[data-process-count]')
                    const blocks = Array.from(meter?.querySelectorAll('[data-scaling-block]') ?? [])
                    const meterRect = meter?.getBoundingClientRect()
                    const trackRect = track?.getBoundingClientRect()
                    const processCountRect = processCount?.getBoundingClientRect()
                    const blockRects = blocks.map((block) => block.getBoundingClientRect())

                    meters[state] = {
                        blockCount: blocks.length,
                        blockHeights: [...new Set(blocks.map((block) => getComputedStyle(block).height))],
                        blockWidths: [...new Set(blocks.map((block) => getComputedStyle(block).width))],
                        blocksDoNotFlex: blocks.every((block) => {
                            const styles = getComputedStyle(block)

                            return styles.flexGrow === '0' && styles.flexShrink === '0'
                        }),
                        blocksDoNotTransform: [...new Set(blocks.map((block) => getComputedStyle(block).transform))],
                        vertical: new Set(blockRects.map((block) => Math.round(block.left))).size === 1
                            && new Set(blockRects.map((block) => Math.round(block.top))).size === blocks.length,
                        blockGaps: [...new Set(blockRects.slice(1).map(
                            (block, index) => Math.round(block.top - blockRects[index].bottom),
                        ))],
                        gapFromCount: Math.round((trackRect?.left ?? 0) - (processCountRect?.right ?? 0)),
                        trackFillsMeter: Math.abs((trackRect?.height ?? 0) - (meterRect?.height ?? 0)) < 0.5,
                        topOffset: meter ? getComputedStyle(meter).top : '',
                        bottomOffset: meter ? getComputedStyle(meter).bottom : '',
                        animationNames: blocks.map((block) => getComputedStyle(block).animationName),
                        animationDelays: [...new Set(blocks.map((block) => getComputedStyle(block).animationDelay))],
                        animationDurations: [...new Set(blocks.map((block) => getComputedStyle(block).animationDuration))],
                        blockOpacities: [...new Set(blocks.map((block) => getComputedStyle(block).opacity))],
                        transitionDurations: [...new Set(blocks.map((block) => getComputedStyle(block).transitionDuration))],
                    }
                }

                return meters
            }
        JS);

        expect($meters)->toBe([
            'up' => [
                'blockCount' => 5,
                'blockHeights' => ['3px'],
                'blockWidths' => ['8px'],
                'blocksDoNotFlex' => true,
                'blocksDoNotTransform' => ['none'],
                'vertical' => true,
                'blockGaps' => [1],
                'gapFromCount' => 8,
                'trackFillsMeter' => true,
                'topOffset' => '4px',
                'bottomOffset' => '4px',
                'animationNames' => ['none', 'none', 'none', 'none', 'none'],
                'animationDelays' => ['0s'],
                'animationDurations' => ['0s'],
                'blockOpacities' => ['1'],
                'transitionDurations' => ['0s'],
            ],
        ]);

        $animationRepeated = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                const meter = document.querySelector('[data-scaling-state="up"]')
                let sawFullMeter = false
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the scaling animation to repeat.')),
                    6000,
                )
                const observer = new MutationObserver(inspect)

                function finish(error = null) {
                    window.clearTimeout(timeout)
                    observer.disconnect()

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve(true)
                }

                function inspect() {
                    const filledBlocks = meter?.getAttribute('data-scaling-filled')

                    if (filledBlocks === '5') {
                        sawFullMeter = true
                    } else if (sawFullMeter && filledBlocks === '1') {
                        finish()
                    }
                }

                if (! meter) {
                    finish(new Error('The scaling indicator is missing.'))
                    return
                }

                observer.observe(meter, {
                    attributes: true,
                    attributeFilter: ['data-scaling-filled'],
                })
                inspect()
            })
        JS);

        expect($animationRepeated)->toBeTrue();

        $page
            ->assertAriaAttribute(
                '[data-scaling-state="up"]',
                'label',
                'Scaling up from 6 processes to 7 processes. Time-based autoscaling with 4 ready jobs.',
            )
            ->hover('[data-scaling-state="up"]')
            ->assertSee('Scaling up from 6 processes to 7 processes')
            ->click('[aria-label="Auto load new entries"]')
            ->assertMissing('[data-scaling-state]');

        if ($autoRefreshWasEnabled) {
            $page->click('[aria-label="Auto load new entries"]');
        }

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });

    it('prevents a parent command while a child command is converging', function (): void {
        $instance = bindBrowserProcessTransitionFixtures();
        $supervisor = $instance.':supervisor-1';

        visit('/horizon/instances')
            ->click("button[aria-label=\"Supervisor {$supervisor} actions\"]")
            ->click('Continue supervisor')
            ->assertSee('Supervisor continue requested.')
            ->assertSee('Continuing')
            ->assertAttribute(
                "button[aria-label=\"Horizon instance {$instance} actions\"]",
                'aria-disabled',
                'true',
            )
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('pauses managed supervisors before allowing one to continue independently', function (): void {
        $instance = bindBrowserProcessTransitionFixtures(supervisorPaused: false);
        $supervisor = $instance.':supervisor-1';
        $page = visit('/horizon/instances');
        $autoRefreshWasEnabled = $page->script(
            '() => localStorage.getItem("horizonAutoLoadsNewEntries") === "1"',
        );

        if ($autoRefreshWasEnabled) {
            $page->click('[aria-label="Auto load new entries"]');
        }

        $instanceActions = "button[aria-label=\"Horizon instance {$instance} actions\"]";
        $supervisorActions = "button[aria-label=\"Supervisor {$supervisor} actions\"]";

        $page
            ->assertAttribute('[aria-label="Auto load new entries"]', 'aria-pressed', 'false')
            ->click($instanceActions)
            ->click('Pause instance')
            ->assertSee('Pausing')
            ->assertSee('Horizon pause requested.')
            ->click($instanceActions)
            ->assertSee('Continue instance')
            ->click($instanceActions)
            ->click($supervisorActions)
            ->click('Continue supervisor')
            ->assertSee('Supervisor continue requested.')
            ->assertSee('Continuing')
            ->assertSee('Paused')
            ->assertSee('Running');

        if ($autoRefreshWasEnabled) {
            $page->click('[aria-label="Auto load new entries"]');
        }

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });

    it('navigates through the mobile sidebar', function (): void {
        visit('/horizon')
            ->on()->iPhone14Pro()
            ->click('Toggle Sidebar')
            ->assertPresent('[data-mobile="true"] a[href$="/horizon/queues"]')
            ->click('[data-mobile="true"] a[href$="/horizon/queues"]')
            ->assertPathIs('/horizon/queues')
            ->assertSee('Queues')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('navigates to consolidated job tabs through the mobile sidebar submenu', function (): void {
        config()->set('zenith.job_navigation_breakdown', true);

        visit('/horizon')
            ->on()->iPhone14Pro()
            ->click('Toggle Sidebar')
            ->assertPresent('[data-mobile="true"] a[href$="/horizon/jobs/pending"]')
            ->assertPresent('[data-mobile="true"] a[href$="/horizon/failed"]')
            ->assertPresent('[data-mobile="true"] a[href$="/horizon/jobs/completed"]')
            ->assertPresent('[data-mobile="true"] a[href$="/horizon/jobs/silenced"]')
            ->click('[data-mobile="true"] a[href$="/horizon/jobs/completed"]')
            ->assertPathIs('/horizon/jobs/completed')
            ->assertMissing('[data-mobile="true"]')
            ->assertSee('Completed jobs')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('filters completed jobs through exact server-owned controls', function (): void {
        $matchingId = bindRetainedJobBrowserFixtures(
            matchingIndex: 5,
            type: RetainedJobType::Completed,
        );
        $page = visit('/horizon/jobs/completed')
            ->assertCount('table tbody:last-of-type tr', 50)
            ->assertMissing("a[href$=\"/{$matchingId}\"]")
            ->click('button[aria-label="Filter jobs"]')
            ->assertSee('Narrow all retained completed jobs with exact server-side filters.')
            ->assertSee('Job class')
            ->assertSee('Queue')
            ->assertSee('Connection')
            ->assertDontSee('Retry status');

        interfaceInteractionsOpenJobFilterSelect($page, 'Job class');

        $page
            ->click('ProductionOnly')
            ->waitForText('Queue: reports')
            ->assertCount('table tbody:last-of-type tr', 1)
            ->assertPresent("a[href$=\"/{$matchingId}\"]")
            ->assertQueryStringHas('filter_job', 'App\\Jobs\\ProductionOnly');

        $filteredResult = $page->script(<<<'JS'
            () => ({
                total: window.history.state?.page?.props?.jobs?.total ?? null,
                ids: (window.history.state?.page?.props?.jobs?.data ?? []).map((job) => job.id),
            })
        JS);

        expect($filteredResult)->toBe([
            'total' => 1,
            'ids' => [$matchingId],
        ]);

        $page
            ->refresh()
            ->assertCount('table tbody:last-of-type tr', 1)
            ->assertPresent("a[href$=\"/{$matchingId}\"]")
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });

    it('searches and filters metrics and queues', function (): void {
        $metrics = visit('/horizon/metrics/jobs');

        $metrics
            ->assertScript('(function () { const list = document.querySelector(\'[role="tablist"]\'); const active = document.querySelector(\'[role="tab"][aria-selected="true"]\'); if (!list || !active) { return false; } const listRect = list.getBoundingClientRect(); const activeRect = active.getBoundingClientRect(); return listRect.top <= activeRect.top && listRect.bottom >= activeRect.bottom; })()')
            ->fill('input[aria-label="Search job metrics"]', 'mail')
            ->assertValue('input[aria-label="Search job metrics"]', 'mail')
            ->assertSee('No matching job metrics')
            ->click('button[aria-label="Filter job metrics"]')
            ->assertSee('Throughput')
            ->assertSee('Runtime')
            ->click('Done')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();

        visit('/horizon/queues')
            ->fill('input[aria-label="Search queues"]', 'a queue that does not exist')
            ->assertValue('input[aria-label="Search queues"]', 'a queue that does not exist')
            ->assertSee('No matching queues')
            ->click('button[aria-label="Filter queues"]')
            ->assertSee('Status')
            ->assertSee('Wait threshold')
            ->click('Done')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps queue actions usable without unsupported pause controls', function (): void {
        visit('/horizon/queues/reports')
            ->click('button[aria-label="Queue actions for reports"]')
            ->assertSee('Clear queue')
            ->assertDontSee('Pause')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('opens the monitor tag workflow without submitting it', function (): void {
        visit('/horizon/monitoring')
            ->click('button[aria-label="Monitor Tag"]')
            ->click('Create tag')
            ->assertSee('Monitor New Tag')
            ->fill('#monitoring-tag', 'App\\Models\\User:6352')
            ->assertValue('#monitoring-tag', 'App\\Models\\User:6352')
            ->click('Cancel')
            ->assertDontSee('Monitor New Tag')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('omits exact batch query controls for an unsupported repository', function (): void {
        visit('/horizon/batches')
            ->assertSee('Exact batch queries unavailable')
            ->assertMissing('input[aria-label="Search batches by name or ID"]')
            ->assertMissing('button[aria-label^="Filter batches"]')
            ->assertMissing('[role="tablist"][aria-label="Batch status"]')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('shows one batch failure row per retry lineage with total attempts', function (): void {
        visit('/horizon/batches/batch-1')
            ->assertSee('Failed Jobs')
            ->assertSee('Attempts')
            ->assertSee('3')
            ->assertPresent('[data-test="batch-failed-job-row"]')
            ->assertCount('[data-test="batch-failed-job-row"]', 1)
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('filters all retained failed jobs by exact tag and server-owned facets', function (): void {
        $matchingId = bindRetainedJobBrowserFixtures(type: RetainedJobType::Failed);
        $page = visit('/horizon/failed')
            ->assertCount('table tbody:last-of-type tr', 50)
            ->assertMissing("a[href$=\"/{$matchingId}\"]")
            ->fill('input[aria-label="Filter failed jobs by exact tag"]', 'tenant:production')
            ->waitForText('Queue: reports')
            ->assertValue(
                'input[aria-label="Filter failed jobs by exact tag"]',
                'tenant:production',
            )
            ->assertCount('table tbody:last-of-type tr', 1)
            ->assertPresent("a[href$=\"/{$matchingId}\"]")
            ->assertQueryStringHas('tag', 'tenant:production')
            ->click('button[aria-label="Filter jobs"]')
            ->assertSee('Narrow all retained failed jobs with exact server-side filters.')
            ->assertSee('Job class')
            ->assertSee('Connection')
            ->assertSee('Queue')
            ->assertDontSee('Retry status');

        interfaceInteractionsOpenJobFilterSelect($page, 'Connection');

        $page
            ->click('redis-secondary')
            ->assertPresent('button[aria-label="Filter jobs, 1 active"]')
            ->assertQueryStringHas('filter_connection', 'redis-secondary');

        $filteredResult = $page->script(<<<'JS'
            () => ({
                total: window.history.state?.page?.props?.jobs?.total ?? null,
                ids: (window.history.state?.page?.props?.jobs?.data ?? []).map((job) => job.id),
            })
        JS);

        expect($filteredResult)->toBe([
            'total' => 1,
            'ids' => [$matchingId],
        ]);

        $page
            ->refresh()
            ->assertCount('table tbody:last-of-type tr', 1)
            ->assertPresent("a[href$=\"/{$matchingId}\"]")
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    });
});

function interfaceInteractionsOpenJobFilterSelect(
    AwaitableWebpage|Webpage $page,
    string $label,
): void {
    $labelJson = json_encode($label, JSON_THROW_ON_ERROR);
    $opened = $page->script(<<<JS
        () => {
            const label = Array.from(document.querySelectorAll('[role="dialog"] label'))
                .find((element) => element.textContent?.trim() === {$labelJson})
            const trigger = label instanceof HTMLLabelElement
                ? document.getElementById(label.htmlFor)
                : null

            if (! trigger) {
                return false
            }

            trigger.click()

            return true
        }
    JS);

    expect($opened)->toBeTrue();
}
