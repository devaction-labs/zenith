<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserInfiniteScrollRefreshFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindBrowserQueueCompletedSummaryRefreshFixtures;

describe('automatic refresh', function (): void {
    it('intercepts asset-version changes in the rendered interface', function (): void {
        $page = visit('/horizon/jobs/pending');

        $defaultPrevented = $page->script(<<<'JS'
            () => {
                const event = new CustomEvent('inertia:location', {
                    cancelable: true,
                    detail: {
                        url: new URL(window.location.href),
                        versionChange: true,
                    },
                })

                document.dispatchEvent(event)

                return event.defaultPrevented
            }
        JS);

        expect($defaultPrevented)->toBeTrue();

        $page
            ->assertPathIs('/horizon/jobs/pending')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('replaces the authoritative first page without an additive merge', function (): void {
        $page = visit('/horizon/jobs/pending')
            ->assertPresent('[aria-label="Auto load new entries"]');

        $pollMetadata = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                let mergeIntent = null
                let reset = null
                let pressedBeforeEnable = null
                let startedAt = null
                const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                const timeout = window.setTimeout(() => {
                    document.removeEventListener('inertia:success', onSuccess)
                    XMLHttpRequest.prototype.setRequestHeader = setRequestHeader
                    reject(new Error('Timed out waiting for the automatic refresh request.'))
                }, 10000)

                function onSuccess(event) {
                    if (event.detail.page.component !== 'Jobs/Index') {
                        return
                    }

                    if (event.detail.page.scrollProps?.jobs?.reset !== true) {
                        return
                    }

                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)
                    XMLHttpRequest.prototype.setRequestHeader = setRequestHeader

                    resolve({
                        mergeIntent,
                        reset,
                        pressedBeforeEnable,
                        elapsedMilliseconds: startedAt === null ? null : Date.now() - startedAt,
                    })
                }

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                function beginObservedPoll() {
                    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                        if (name.toLowerCase() === 'x-inertia-reset') {
                            reset = value
                        }

                        if (name.toLowerCase() === 'x-inertia-infinite-scroll-merge-intent') {
                            mergeIntent = value
                        }

                        return setRequestHeader.call(this, name, value)
                    }

                    document.addEventListener('inertia:success', onSuccess)
                    startedAt = Date.now()
                    pressedBeforeEnable = toggle?.getAttribute('aria-pressed')
                    toggle?.click()
                }

                if (toggle?.getAttribute('aria-pressed') === 'true') {
                    toggle.click()
                    window.requestAnimationFrame(() => window.requestAnimationFrame(beginObservedPoll))
                } else {
                    beginObservedPoll()
                }
            })
        JS);

        if (! is_array($pollMetadata)) {
            throw new LogicException('Expected the automatic refresh probe to resolve an object.');
        }

        expect($pollMetadata)->toMatchArray([
            'mergeIntent' => null,
            'reset' => 'jobs',
            'pressedBeforeEnable' => 'false',
        ])->and($pollMetadata['elapsedMilliseconds'])->toBeLessThan(1000);

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('removes rows that no longer exist when the first page refreshes', function (): void {
        bindBrowserInfiniteScrollRefreshFixtures(emptyOnRefresh: true);

        $page = visit('/horizon/failed?starting_at=-1')
            ->assertPresent('a[href$="/failed-149"]');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                let mergeIntent = null
                let reset = null
                let responseRowCount = null
                let responseReset = null
                const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the authoritative empty page.')),
                    10000,
                )

                function finish(error = null) {
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)
                    XMLHttpRequest.prototype.setRequestHeader = setRequestHeader

                    if (error) {
                        reject(error)
                        return
                    }

                    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                        const rows = Array.from(document.querySelectorAll('main tbody tr'))
                            .filter((row) => row.querySelector('a[href*="/failed/"]') !== null)

                        resolve({
                            mergeIntent,
                            reset,
                            responseRowCount,
                            responseReset,
                            rowCount: rows.length,
                            emptyStateVisible: document.body.textContent?.includes('No failed jobs') ?? false,
                        })
                    }))
                }

                function onSuccess(event) {
                    if (
                        event.detail.page.component === 'FailedJobs/Index'
                        && event.detail.page.scrollProps?.jobs?.reset === true
                    ) {
                        responseRowCount = event.detail.page.props.jobs?.data?.length ?? null
                        responseReset = event.detail.page.scrollProps?.jobs?.reset ?? null
                        finish()
                    }
                }

                function beginRefresh() {
                    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                        if (name.toLowerCase() === 'x-inertia-reset') {
                            reset = value
                        }

                        if (name.toLowerCase() === 'x-inertia-infinite-scroll-merge-intent') {
                            mergeIntent = value
                        }

                        return setRequestHeader.call(this, name, value)
                    }

                    document.addEventListener('inertia:success', onSuccess)
                    document.querySelector('[aria-label="Auto load new entries"]')?.click()
                }

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                if (toggle?.getAttribute('aria-pressed') === 'true') {
                    toggle.click()
                    window.requestAnimationFrame(() => window.requestAnimationFrame(beginRefresh))
                } else {
                    beginRefresh()
                }
            })
        JS);

        expect($result)->toBe([
            'mergeIntent' => null,
            'reset' => 'jobs',
            'responseRowCount' => 0,
            'responseReset' => true,
            'rowCount' => 0,
            'emptyStateVisible' => true,
        ]);

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('refreshes the loaded infinite-scroll head without showing a reload banner', function (): void {
        bindBrowserInfiniteScrollRefreshFixtures();

        $page = visit('/horizon/failed?starting_at=-1');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                const dataRows = () => Array.from(document.querySelectorAll('main tbody tr'))
                    .filter((row) => row.querySelector('a[href*="/failed/"]') !== null)
                const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                let loadedRowCount = null
                let loadingFallbackSeen = false
                let midpointReached = false
                let mergeIntent = null
                let reset = null
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the refreshed failed jobs.')),
                    10000,
                )
                const observer = new MutationObserver(inspect)

                function finish(error = null, value = null) {
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)
                    observer.disconnect()
                    XMLHttpRequest.prototype.setRequestHeader = setRequestHeader

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve(value)
                }

                XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                    if (name.toLowerCase() === 'x-inertia-reset') {
                        reset = value
                    }

                    if (name.toLowerCase() === 'x-inertia-infinite-scroll-merge-intent') {
                        mergeIntent = value
                    }

                    return setRequestHeader.call(this, name, value)
                }

                function onSuccess(event) {
                    if (
                        event.detail.page.component !== 'FailedJobs/Index'
                        || !event.detail.page.prependProps?.includes('jobs.data')
                    ) {
                        return
                    }

                    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                        const rows = dataRows()
                        const updatedRow = rows.find(
                            (row) => row.querySelector('a[href$="/failed-149"]') !== null,
                        )
                        const toggle = document.querySelector(
                            '[aria-label="Auto load new entries"]',
                        )

                        finish(null, {
                            loadedRowCount,
                            refreshedRowCount: rows.length,
                            existingRowUpdated: updatedRow?.textContent?.includes('RefreshedImportFeed') ?? false,
                            newEntryVisible: document.querySelector(
                                'a[href$="/failed-150"]',
                            ) !== null,
                            reloadBannerVisible: Array.from(document.querySelectorAll('a'))
                                .some((link) => link.textContent?.trim() === 'Reload'),
                            autoRefreshEnabled: toggle?.getAttribute('aria-pressed'),
                            loadingFallbackSeen,
                            midpointReached,
                            mergeIntent,
                            reset,
                        })
                    }))
                }

                function inspect() {
                    loadingFallbackSeen ||= document.querySelector(
                        '[aria-label^="Loading more"]',
                    ) !== null

                    const rows = dataRows()
                    if (loadedRowCount === null && rows.length >= 100) {
                        loadedRowCount = rows.length
                        const maximumScroll = document.documentElement.scrollHeight - window.innerHeight
                        window.scrollTo(0, Math.floor(maximumScroll / 2))
                        midpointReached = window.scrollY > 0 && window.scrollY < maximumScroll

                        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                            const toggle = document.querySelector(
                                '[aria-label="Auto load new entries"]',
                            )

                            if (toggle?.getAttribute('aria-pressed') !== 'true') {
                                toggle?.click()
                            }
                        }))

                        return
                    }
                }

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                if (toggle?.getAttribute('aria-pressed') === 'true') {
                    toggle.click()
                }

                document.addEventListener('inertia:success', onSuccess)
                observer.observe(document.querySelector('main'), {
                    childList: true,
                    subtree: true,
                    characterData: true,
                })
                window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                    window.scrollTo(0, document.documentElement.scrollHeight)
                    inspect()
                }))
            })
        JS);

        if (! is_array($result) || ! is_int($result['loadedRowCount'] ?? null)) {
            throw new LogicException('Expected the infinite-scroll refresh probe to report the loaded row count.');
        }

        expect($result['loadedRowCount'])->toBeGreaterThanOrEqual(100)
            ->and($result['refreshedRowCount'])->toBe($result['loadedRowCount'] + 1)
            ->and($result['existingRowUpdated'])->toBeTrue()
            ->and($result['newEntryVisible'])->toBeTrue()
            ->and($result['reloadBannerVisible'])->toBeFalse()
            ->and($result['autoRefreshEnabled'])->toBe('true')
            ->and($result['loadingFallbackSeen'])->toBeFalse()
            ->and($result['midpointReached'])->toBeTrue()
            ->and($result['mergeIntent'])->toBe('prepend')
            ->and($result['reset'])->toBeNull();

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps the third-page frontier while loaded history refreshes', function (): void {
        bindBrowserInfiniteScrollRefreshFixtures();

        $page = visit('/horizon/failed?starting_at=-1');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                const dataRows = () => Array.from(document.querySelectorAll('main tbody tr'))
                    .filter((row) => row.querySelector('a[href*="/failed/"]') !== null)
                const originalOpen = XMLHttpRequest.prototype.open
                const originalAbort = XMLHttpRequest.prototype.abort
                const originalSend = XMLHttpRequest.prototype.send
                const originalSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                const requestHeaders = new WeakMap()
                const requestUrls = new WeakMap()
                const appendRequests = new WeakSet()
                const appendCursors = []
                const prependCursors = []
                let cancelledAppendRequests = 0
                let loadingFallbackSeen = false
                let stage = 'loading-second-page'
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the third failed-jobs page.')),
                    15000,
                )
                const observer = new MutationObserver(inspect)

                function finish(error = null, value = null) {
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', inspect)
                    observer.disconnect()
                    XMLHttpRequest.prototype.open = originalOpen
                    XMLHttpRequest.prototype.abort = originalAbort
                    XMLHttpRequest.prototype.send = originalSend
                    XMLHttpRequest.prototype.setRequestHeader = originalSetRequestHeader

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve(value)
                }

                XMLHttpRequest.prototype.open = function (method, url, ...args) {
                    requestUrls.set(this, String(url))

                    return originalOpen.call(this, method, url, ...args)
                }

                XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                    const headers = requestHeaders.get(this) ?? {}
                    headers[name.toLowerCase()] = value
                    requestHeaders.set(this, headers)

                    return originalSetRequestHeader.call(this, name, value)
                }

                XMLHttpRequest.prototype.send = function (...args) {
                    const headers = requestHeaders.get(this) ?? {}
                    const mergeIntent = headers['x-inertia-infinite-scroll-merge-intent']
                    const requestUrl = new URL(requestUrls.get(this) ?? window.location.href, window.location.href)
                    const cursor = requestUrl.searchParams.get('starting_at')

                    if (mergeIntent === 'append') {
                        appendRequests.add(this)
                        appendCursors.push(cursor)
                    }

                    if (mergeIntent === 'prepend') {
                        prependCursors.push(cursor)
                    }

                    return originalSend.apply(this, args)
                }

                XMLHttpRequest.prototype.abort = function (...args) {
                    if (appendRequests.has(this)) {
                        cancelledAppendRequests++
                    }

                    return originalAbort.apply(this, args)
                }

                function inspect() {
                    loadingFallbackSeen ||= document.querySelector(
                        '[aria-label^="Loading more"]',
                    ) !== null

                    const rows = dataRows()
                    if (stage === 'loading-second-page' && rows.length === 100) {
                        stage = 'settling-second-page'
                        window.scrollTo(0, 0)

                        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                            stage = 'polling-first-page'

                            const toggle = document.querySelector(
                                '[aria-label="Auto load new entries"]',
                            )

                            if (toggle?.getAttribute('aria-pressed') !== 'true') {
                                toggle.click()
                            }
                        }))

                        return
                    }

                    if (
                        stage === 'polling-first-page'
                        && rows.length === 101
                        && prependCursors.length > 0
                        && document.querySelector('a[href$="/failed-150"]') !== null
                    ) {
                        stage = 'loading-third-page'
                        window.scrollTo(0, document.documentElement.scrollHeight)

                        return
                    }

                    if (stage !== 'loading-third-page' || rows.length !== 150) {
                        return
                    }

                    const ids = rows.map(
                        (row) => row.querySelector('a[href*="/failed/"]')?.getAttribute('href'),
                    )
                    finish(null, {
                        rowCount: rows.length,
                        uniqueRowCount: new Set(ids).size,
                        appendCursors,
                        prependCursors,
                        cancelledAppendRequests,
                        loadingFallbackSeen,
                        reloadBannerVisible: Array.from(document.querySelectorAll('a'))
                            .some((link) => link.textContent?.trim() === 'Reload'),
                        search: window.location.search,
                    })
                }

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                if (toggle?.getAttribute('aria-pressed') === 'true') {
                    toggle.click()
                }

                document.addEventListener('inertia:success', inspect)
                observer.observe(document.querySelector('main'), {
                    childList: true,
                    subtree: true,
                })
                window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                    window.scrollTo(0, document.documentElement.scrollHeight)
                    inspect()
                }))
            })
        JS);

        if (! is_array($result)) {
            throw new LogicException('Expected the third-page frontier probe to resolve an object.');
        }

        expect($result['rowCount'])->toBe(150)
            ->and($result['uniqueRowCount'])->toBe(150)
            ->and($result['appendCursors'])->toContain('100')
            ->and($result['appendCursors'])->toContain('50')
            ->and($result['prependCursors'])->toContain(null)
            ->and($result['cancelledAppendRequests'])->toBe(0)
            ->and($result['loadingFallbackSeen'])->toBeFalse()
            ->and($result['reloadBannerVisible'])->toBeFalse()
            ->and($result['search'])->toBe('?starting_at=-1');

        $page
            ->refresh()
            ->assertQueryStringHas('starting_at', '-1')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps newer queue query navigation when an older poll response finishes', function (): void {
        bindBrowserPageFixtures();
        config()->set('zenith.poll_interval', 200);

        $page = visit('/horizon/queues/reports')
            ->waitForText('Retained Pending Jobs');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                const originalSend = XMLHttpRequest.prototype.send
                const originalSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader
                const headers = new WeakMap()
                let heldPoll = null
                let navigationStarted = false
                let metricsNavigationComplete = false
                let scopeNavigationComplete = false
                let followingPollStarted = false
                let waitingForSilencedJob = false
                let finished = false
                const timeout = window.setTimeout(() => finish(new Error('Timed out reproducing the poll/navigation overlap.')), 10000)

                function finish(error = null) {
                    if (finished) {
                        return
                    }

                    finished = true
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)
                    XMLHttpRequest.prototype.send = originalSend
                    XMLHttpRequest.prototype.setRequestHeader = originalSetRequestHeader

                    if (error) {
                        reject(error)
                        return
                    }

                    const metrics = Array.from(document.querySelectorAll('[role="tab"]'))
                        .find((tab) => tab.textContent?.trim() === 'Metrics')
                    const silenced = Array.from(document.querySelectorAll('[role="tab"]'))
                        .find((tab) => tab.textContent?.includes('Silenced Jobs'))
                    const page = window.history.state?.page

                    resolve({
                        url: `${window.location.pathname}${window.location.search}`,
                        metricsSelected: metrics?.getAttribute('aria-selected'),
                        silencedSelected: silenced?.getAttribute('aria-selected'),
                        view: page?.props?.view,
                        tab: page?.props?.tab,
                        activityId: page?.props?.activity?.data?.[0]?.id,
                        hasMetricsHeading: Array.from(document.querySelectorAll('h2'))
                            .some((heading) => heading.textContent?.trim() === 'Throughput — reports'),
                        hasSilencedJob: document.querySelector('a[href*="silenced-1"]') !== null,
                    })
                }

                function finishWhenSilencedJobRenders() {
                    if (document.querySelector('a[href*="silenced-1"]')) {
                        finish()
                        return
                    }

                    window.requestAnimationFrame(finishWhenSilencedJobRenders)
                }

                function onSuccess(event) {
                    if (!navigationStarted || event.detail.page.component !== 'Queues/Show') {
                        return
                    }

                    if (!metricsNavigationComplete && event.detail.page.props.view === 'metrics') {
                        const silenced = Array.from(document.querySelectorAll('[role="tab"]'))
                            .find((tab) => tab.textContent?.includes('Silenced Jobs'))

                        if (!(silenced instanceof HTMLElement)) {
                            return
                        }

                        metricsNavigationComplete = true
                        silenced.click()
                        return
                    }

                    if (
                        metricsNavigationComplete &&
                        !scopeNavigationComplete &&
                        event.detail.page.props.tab === 'silenced' &&
                        event.detail.page.props.activity?.data?.[0]?.id === 'silenced-1'
                    ) {
                        scopeNavigationComplete = true
                        heldPoll?.()
                        return
                    }

                    if (scopeNavigationComplete && followingPollStarted && !waitingForSilencedJob) {
                        waitingForSilencedJob = true
                        window.requestAnimationFrame(finishWhenSilencedJobRenders)
                    }
                }

                XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                    const requestHeaders = headers.get(this) ?? {}
                    requestHeaders[name.toLowerCase()] = String(value)
                    headers.set(this, requestHeaders)

                    return originalSetRequestHeader.call(this, name, value)
                }

                XMLHttpRequest.prototype.send = function (body) {
                    const partialData = headers.get(this)?.['x-inertia-partial-data'] ?? ''

                    if (
                        !heldPoll
                        && partialData.includes('activity')
                        && partialData.includes('listRevision')
                        && !partialData.includes('summary')
                    ) {
                        const onload = this.onload

                        this.onload = (event) => {
                            heldPoll = () => onload?.call(this, event)
                            navigationStarted = true

                            const metrics = Array.from(document.querySelectorAll('[role="tab"]'))
                                .find((tab) => tab.textContent?.trim() === 'Metrics')

                            if (!(metrics instanceof HTMLElement)) {
                                finish(new Error('The Metrics queue tab was not found.'))
                                return
                            }

                            document.addEventListener('inertia:success', onSuccess)
                            metrics.click()
                        }
                    } else if (
                        scopeNavigationComplete &&
                        partialData.includes('activity') &&
                        partialData.includes('listRevision') &&
                        !partialData.includes('summary')
                    ) {
                        followingPollStarted = true
                    }

                    return originalSend.call(this, body)
                }

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                if (!(toggle instanceof HTMLElement)) {
                    finish(new Error('The auto-load toggle was not found.'))
                    return
                }

                if (toggle.getAttribute('aria-pressed') !== 'true') {
                    toggle.click()
                }
            })
        JS);

        expect($result)->toBe([
            'url' => '/horizon/queues/reports?tab=silenced&view=metrics',
            'metricsSelected' => 'true',
            'silencedSelected' => 'true',
            'view' => 'metrics',
            'tab' => 'silenced',
            'activityId' => 'silenced-1',
            'hasMetricsHeading' => true,
            'hasSilencedJob' => true,
        ]);

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('shows a restrained failed auto-refresh state until the next successful poll', function (): void {
        config()->set('zenith.poll_interval', 1000);
        config()->set('zenith.testing.fail_next_tracked_list_refresh', true);
        app(Kernel::class)->pushMiddleware(FailNextTrackedListRefreshOnce::class);

        $page = visit('/horizon/jobs/pending')
            ->assertPresent('[aria-label="Auto load new entries"]');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                let sawFailedState = false
                let finished = false
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the auto-refresh failure state.')),
                    10000,
                )
                const observer = new MutationObserver(() => inspect())

                function finish(error = null, value = null) {
                    if (finished) {
                        return
                    }

                    finished = true
                    window.clearTimeout(timeout)
                    observer.disconnect()

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve(value)
                }

                function autoRefreshControl() {
                    return document.querySelector('[data-refresh-status]')
                        ?? document.querySelector('[aria-label^="Auto"]')
                }

                function afterPaint(callback) {
                    window.requestAnimationFrame(() => window.requestAnimationFrame(callback))
                }

                function inspect() {
                    const control = autoRefreshControl()
                    const status = control?.getAttribute('data-refresh-status')
                    const label = control?.getAttribute('aria-label')
                    const description = control?.getAttribute('aria-description')
                    const toastCount = document.querySelectorAll('[data-sonner-toast]').length

                    if (!sawFailedState && status === 'failed') {
                        sawFailedState = true

                        if (
                            label !== 'Auto refresh failed; retrying automatically'
                            || !description?.includes('keep retrying')
                        ) {
                            finish(new Error('The failed auto-refresh control was not described accessibly.'))
                            return
                        }

                        if (toastCount > 0) {
                            finish(new Error('Automatic refresh failures must not create toasts.'))
                            return
                        }

                        return
                    }

                    if (
                        sawFailedState
                        && status === 'idle'
                        && label === 'Auto load new entries'
                        && !control?.hasAttribute('aria-description')
                    ) {
                        finish(null, {
                            recoveredStatus: status,
                            recoveredLabel: label,
                            toastCount,
                        })
                    }
                }

                function enableAfterObserverReady() {
                    observer.observe(document.body, {
                        attributes: true,
                        attributeFilter: ['aria-label', 'aria-description', 'data-refresh-status'],
                        childList: true,
                        subtree: true,
                    })

                    const toggle = autoRefreshControl()

                    if (!(toggle instanceof HTMLElement)) {
                        finish(new Error('The auto-refresh toggle was not found.'))
                        return
                    }

                    // Enable triggers the immediate tracked reload that should 503 once.
                    if (toggle.getAttribute('aria-pressed') !== 'true') {
                        toggle.click()
                    }

                    inspect()
                }

                const toggle = autoRefreshControl()

                if (!(toggle instanceof HTMLElement)) {
                    finish(new Error('The auto-refresh toggle was not found.'))
                    return
                }

                // Stop any default-enabled polling before the first interval fires, then
                // re-enable under observation so the one-shot 503 is not consumed early.
                if (toggle.getAttribute('aria-pressed') === 'true') {
                    toggle.click()
                }

                afterPaint(enableAfterObserverReady)
            })
        JS);

        if (! is_array($result)) {
            throw new LogicException('Expected the failed auto-refresh probe to resolve an object.');
        }

        expect($result['recoveredStatus'])->toBe('idle')
            ->and($result['recoveredLabel'])->toBe('Auto load new entries')
            ->and($result['toastCount'])->toBe(0);

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });

    it('keeps the last completed queue count while a refresh is still calculating', function (): void {
        bindBrowserQueueCompletedSummaryRefreshFixtures();

        $page = visit('/horizon/queues/reports');

        $result = $page->script(<<<'JS'
            () => new Promise((resolve, reject) => {
                let sawUnavailableRefresh = false
                let retainedCountOccurrences = null
                let warningVisible = null
                const timeout = window.setTimeout(
                    () => finish(new Error('Timed out waiting for the completed queue summary to recover.')),
                    10000,
                )

                function completedCountOccurrences(value) {
                    return (document.body.innerText.match(new RegExp(`\\b${value}\\b`, 'g')) ?? []).length
                }

                function finish(error = null) {
                    window.clearTimeout(timeout)
                    document.removeEventListener('inertia:success', onSuccess)

                    if (error) {
                        reject(error)
                        return
                    }

                    resolve({
                        retainedCountOccurrences,
                        recoveredCountOccurrences: completedCountOccurrences(37),
                        warningVisible,
                    })
                }

                function afterPaint(callback) {
                    window.requestAnimationFrame(() => window.requestAnimationFrame(callback))
                }

                function onSuccess(event) {
                    if (event.detail.page.component !== 'Queues/Show') {
                        return
                    }

                    const summary = event.detail.page.props.summary

                    if (!sawUnavailableRefresh && summary?.completedAvailable === false) {
                        sawUnavailableRefresh = true
                        afterPaint(() => {
                            retainedCountOccurrences = completedCountOccurrences(36)
                            warningVisible =
                                document.body.innerText.includes('Some queue data is unavailable') ||
                                document.body.innerText.includes(
                                    'Some retained job data is currently unavailable.',
                                )
                        })

                        return
                    }

                    if (
                        sawUnavailableRefresh &&
                        summary?.completedAvailable === true &&
                        summary?.completedJobs === 37
                    ) {
                        afterPaint(() => finish())
                    }
                }

                if (completedCountOccurrences(36) < 2) {
                    finish(new Error('The initial completed queue summary was not rendered.'))
                    return
                }

                document.addEventListener('inertia:success', onSuccess)

                const toggle = document.querySelector('[aria-label="Auto load new entries"]')

                if (!(toggle instanceof HTMLElement)) {
                    finish(new Error('The auto-load toggle was not found.'))
                    return
                }

                if (toggle.getAttribute('aria-pressed') !== 'true') {
                    toggle.click()
                }
            })
        JS);

        if (! is_array($result)) {
            throw new LogicException('Expected the completed queue summary probe to resolve an object.');
        }

        expect($result['retainedCountOccurrences'])->toBeGreaterThanOrEqual(2)
            ->and($result['recoveredCountOccurrences'])->toBeGreaterThanOrEqual(2)
            ->and($result['warningVisible'])->toBeFalse();

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    });
});

/**
 * Test-only: fail the next tracked list auto-refresh (partial props include listRevision)
 * with a one-shot 503 so the UI can show failed → idle without XHR interception.
 *
 * Armed via config so Pest Browser's separate app process sees the same flag.
 */
final class FailNextTrackedListRefreshOnce
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $partialData = (string) $request->headers->get('X-Inertia-Partial-Data', '');

        if (
            (bool) config('zenith.testing.fail_next_tracked_list_refresh')
            && $request->headers->get('X-Inertia') === 'true'
            && str_contains($partialData, 'listRevision')
        ) {
            config()->set('zenith.testing.fail_next_tracked_list_refresh', false);

            return response('Automatic refresh temporarily unavailable.', 503);
        }

        return $next($request);
    }
}
