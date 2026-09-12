<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/RetainedJobBrowserFixtures.php';

use DevactionLabs\Zenith\Jobs\JobListType;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobType;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\bindRetainedJobBrowserFixtures;

it('filters the complete retained pending job set and resets its cursor', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures();
    $cursor = app(JobsData::class)->page(JobListType::Pending, null)->next;

    expect($cursor)->toBeString();
    assert(is_string($cursor));
    expect(strlen($cursor))->toBeGreaterThan(0);

    $page = visit('/horizon/jobs/pending?starting_at='.urlencode($cursor))
        ->assertQueryStringHas('starting_at', $cursor)
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $initialTotal = $page->script(
        '() => window.history.state?.page?.props?.jobs?.total ?? null',
    );

    expect($initialTotal)->toBe(110);

    $page
        ->click('button[aria-label="Filter jobs"]')
        ->assertSee('Narrow all retained pending jobs with exact server-side filters.');

    $openFilterSelect = static function (string $label) use ($page): void {
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
    };

    $openFilterSelect('Job class');
    $page
        ->assertSee('ProductionOnly')
        ->click('ProductionOnly')
        ->waitForText('Queue: reports')
        ->assertCount('table tbody:last-of-type tr', 1)
        ->assertPresent("a[href$=\"/{$matchingId}\"]")
        ->assertQueryStringHas('filter_job', 'App\\Jobs\\ProductionOnly')
        ->assertQueryStringMissing('starting_at');

    $filteredResult = $page->script(<<<'JS'
        () => ({
            total: window.history.state?.page?.props?.jobs?.total ?? null,
            rows: window.history.state?.page?.props?.jobs?.data?.length ?? null,
        })
    JS);

    expect($filteredResult)->toBe([
        'total' => 1,
        'rows' => 1,
    ]);

    $openFilterSelect('Queue');
    $page
        ->assertSee('reports')
        ->click('reports')
        ->waitForText('Queue: reports')
        ->assertQueryStringHas('filter_queue', 'reports')
        ->assertCount('table tbody:last-of-type tr', 1);

    $openFilterSelect('Connection');
    $page
        ->assertSee('redis-secondary')
        ->click('redis-secondary')
        ->waitForText('Queue: reports')
        ->assertQueryStringHas('filter_connection', 'redis-secondary')
        ->assertCount('table tbody:last-of-type tr', 1);

    $openFilterSelect('State');
    $page
        ->click('[data-slot="select-item"]:has-text("Reserved")')
        ->waitForText('Reserved')
        ->assertQueryStringHas('filter_state', 'reserved')
        ->assertCount('table tbody:last-of-type tr', 1)
        ->assertPresent("a[href$=\"/{$matchingId}\"]");

    $finalResult = $page->script(<<<'JS'
        () => ({
            total: window.history.state?.page?.props?.jobs?.total ?? null,
            ids: (window.history.state?.page?.props?.jobs?.data ?? []).map((job) => job.id),
        })
    JS);

    expect($finalResult)->toBe([
        'total' => 1,
        'ids' => [$matchingId],
    ]);

    $page
        ->assertMissing('[data-slot="select-content"]:visible')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('continues an exact retained query through an opaque browser cursor', function (): void {
    bindRetainedJobBrowserFixtures(
        type: RetainedJobType::Completed,
        allMatching: true,
    );

    $page = visit('/horizon/jobs/completed')
        ->click('button[aria-label="Filter jobs"]');

    $jobClassLabel = json_encode('Job class', JSON_THROW_ON_ERROR);
    $opened = $page->script(<<<JS
        () => {
            const label = Array.from(document.querySelectorAll('[role="dialog"] label'))
                .find((element) => element.textContent?.trim() === {$jobClassLabel})
            const trigger = label instanceof HTMLLabelElement
                ? document.getElementById(label.htmlFor)
                : null

            trigger?.click()

            return trigger !== null
        }
    JS);

    expect($opened)->toBeTrue();

    $page
        ->click('[data-slot="select-item"]:has-text("ProductionOnly")')
        ->waitForText('Queue: reports')
        ->assertCount('table tbody:last-of-type tr', 50);

    $result = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const rows = () => document.querySelectorAll('table tbody:last-of-type tr').length
            const timeout = window.setTimeout(
                () => finish(new Error('Timed out waiting for all exact retained rows.')),
                10000,
            )
            const observer = new MutationObserver(inspect)

            function finish(error = null, value = null) {
                window.clearTimeout(timeout)
                observer.disconnect()

                if (error) {
                    reject(error)
                    return
                }

                resolve(value)
            }

            function inspect() {
                if (rows() < 110) {
                    window.scrollTo(0, document.documentElement.scrollHeight)
                    return
                }

                finish(null, {
                    rowCount: rows(),
                    total: window.history.state?.page?.props?.jobs?.total ?? null,
                    search: window.location.search,
                })
            }

            observer.observe(document.querySelector('main'), {
                childList: true,
                subtree: true,
            })
            window.scrollTo(0, document.documentElement.scrollHeight)
            inspect()
        })
    JS);

    expect($result)->toBe([
        'rowCount' => 110,
        'total' => 110,
        'search' => '?filter_job=App%5CJobs%5CProductionOnly',
    ]);

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('shows the newest retained completed jobs first by default', function (): void {
    bindRetainedJobBrowserFixtures(
        matchingIndex: 5,
        type: RetainedJobType::Completed,
    );

    $page = visit('/horizon/jobs/completed')
        ->assertCount('table tbody:last-of-type tr', 50);

    $result = $page->script(<<<'JS'
        () => ({
            ids: Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            ),
            completedSort: document
                .querySelector('table thead th:nth-child(4)')
                ?.getAttribute('aria-sort') ?? null,
        })
    JS);

    if (! is_array($result) || ! is_array($result['ids'] ?? null)) {
        throw new LogicException('Expected the completed jobs probe to report the rendered ids.');
    }

    expect($result['ids'][0] ?? null)->toBe('completed-109')
        ->and($result['ids'][49] ?? null)->toBe('completed-060')
        ->and($result['completedSort'])->toBe('descending');

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('finds a partial job class match beyond the first 50 retained jobs', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures(
        matchingIndex: 5,
        type: RetainedJobType::Completed,
    );

    $page = visit('/horizon/jobs/completed')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]")
        ->fill(
            'input[aria-label="Search completed jobs by class or ID"]',
            'production',
        )
        ->waitForText('Queue: reports')
        ->assertQueryStringHas('query', 'production')
        ->assertCount('table tbody:last-of-type tr', 1)
        ->assertPresent("a[href$=\"/{$matchingId}\"]");

    $result = $page->script(<<<'JS'
        () => ({
            total: window.history.state?.page?.props?.jobs?.total ?? null,
            ids: (window.history.state?.page?.props?.jobs?.data ?? []).map((job) => job.id),
        })
    JS);

    expect($result)->toBe([
        'total' => 1,
        'ids' => [$matchingId],
    ]);

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('finds an exact job ID beyond the first 50 retained jobs', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures(
        matchingIndex: 5,
        type: RetainedJobType::Completed,
    );

    $page = visit('/horizon/jobs/completed')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]")
        ->fill(
            'input[aria-label="Search completed jobs by class or ID"]',
            $matchingId,
        )
        ->waitForText('Queue: reports')
        ->assertQueryStringHas('query', $matchingId)
        ->assertCount('table tbody:last-of-type tr', 1)
        ->assertPresent("a[href$=\"/{$matchingId}\"]");

    $result = $page->script(<<<'JS'
        () => ({
            total: window.history.state?.page?.props?.jobs?.total ?? null,
            ids: (window.history.state?.page?.props?.jobs?.data ?? []).map((job) => job.id),
        })
    JS);

    expect($result)->toBe([
        'total' => 1,
        'ids' => [$matchingId],
    ]);

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('loads the job filter catalog on intent after soft navigation without polling it', function (): void {
    bindRetainedJobBrowserFixtures(type: RetainedJobType::Completed);
    config()->set('zenith.poll_interval', 200);

    $page = visit('/horizon/jobs/pending');

    $result = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const originalSend = XMLHttpRequest.prototype.send
            const originalSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader
            const headers = new WeakMap()
            const requests = []
            const timeout = window.setTimeout(
                () => finish(new Error('Timed out waiting for a completed-jobs poll.')),
                10000,
            )

            function restore() {
                window.clearTimeout(timeout)
                document.removeEventListener('inertia:success', onSuccess)
                XMLHttpRequest.prototype.send = originalSend
                XMLHttpRequest.prototype.setRequestHeader = originalSetRequestHeader
            }

            function finish(error = null, value = null) {
                restore()

                if (error) {
                    reject(error)
                    return
                }

                resolve(value)
            }

            function onSuccess(event) {
                if (event.detail.page.component !== 'Jobs/Index') {
                    return
                }

                const pageUrl = new URL(event.detail.page.url, window.location.origin)
                const completedPoll = requests.find(
                    (request) =>
                        request.path === '/horizon/jobs/completed' &&
                        request.partialData.includes('jobs'),
                )

                if (pageUrl.pathname !== '/horizon/jobs/completed' || !completedPoll) {
                    return
                }

                finish(null, {
                    marker: window.__retainedJobSoftNavigationMarker,
                    path: pageUrl.pathname,
                    pollIncludedCatalog: completedPoll.partialData.includes('filterCatalog'),
                })
            }

            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                const requestHeaders = headers.get(this) ?? {}
                requestHeaders[name.toLowerCase()] = String(value)
                headers.set(this, requestHeaders)

                return originalSetRequestHeader.call(this, name, value)
            }

            XMLHttpRequest.prototype.send = function (body) {
                const requestHeaders = headers.get(this) ?? {}
                const partialData = (requestHeaders['x-inertia-partial-data'] ?? '')
                    .split(',')
                    .map((value) => value.trim())
                requests.push({
                    path: window.location.pathname,
                    partialData,
                })

                return originalSend.call(this, body)
            }

            document.addEventListener('inertia:success', onSuccess)
            window.__retainedJobSoftNavigationMarker = 'preserved'

            const beginNavigation = () => {
                const completedTab = Array.from(document.querySelectorAll('main [role="tab"]'))
                    .find((tab) => tab.textContent?.includes('Completed'))

                if (!(completedTab instanceof HTMLElement)) {
                    finish(new Error('The Completed jobs tab was not found.'))
                    return
                }

                completedTab.click()
            }

            const toggle = document.querySelector('[aria-label="Auto load new entries"]')

            if (!(toggle instanceof HTMLElement)) {
                finish(new Error('The auto-refresh toggle was not found.'))
                return
            }

            if (toggle.getAttribute('aria-pressed') === 'true') {
                beginNavigation()
            } else {
                toggle.click()
                beginNavigation()
            }
        })
    JS);

    expect($result)->toBe([
        'marker' => 'preserved',
        'path' => '/horizon/jobs/completed',
        'pollIncludedCatalog' => false,
    ]);

    $page->click('button[aria-label="Filter jobs"]');
    $opened = $page->script(<<<'JS'
        () => {
            const label = Array.from(document.querySelectorAll('[role="dialog"] label'))
                .find((element) => element.textContent?.trim() === 'Job class')
            const trigger = label instanceof HTMLLabelElement
                ? document.getElementById(label.htmlFor)
                : null

            trigger?.click()

            return trigger !== null
        }
    JS);

    expect($opened)->toBeTrue();

    $classes = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const deadline = performance.now() + 10000

            function inspect() {
                const classes = Array.from(
                    document.querySelectorAll('[data-slot="select-item"]'),
                    (item) => item.textContent?.trim(),
                )

                if (classes.includes('ProductionOnly')) {
                    resolve(classes)
                    return
                }

                if (performance.now() >= deadline) {
                    reject(new Error('Timed out waiting for the intent-loaded job catalog.'))
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($classes)
        ->toContain('ProductionOnly')
        ->not()->toContain('DemoSilencedJob');

    $page
        ->click('[data-slot="select-item"]:has-text("All job classes")');

    $selectClosed = $page->script(<<<'JS'
        () => new Promise((resolve) => {
            const deadline = performance.now() + 1000

            function inspect() {
                const select = document.querySelector('[data-slot="select-content"]')
                const closed = select === null || select.parentElement?.hidden === true

                if (closed || performance.now() >= deadline) {
                    resolve(closed)
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($selectClosed)->toBeTrue();

    $page->click('Done');

    $overlaysClosed = $page->script(<<<'JS'
        () => new Promise((resolve) => {
            const deadline = performance.now() + 1000

            function inspect() {
                const dialogClosed = document.querySelector(
                    '[data-slot="dialog-content"]',
                ) === null
                const selectsHidden = Array.from(
                    document.querySelectorAll('[data-slot="select-content"]'),
                ).every((select) => select.parentElement?.hidden === true)
                const closed = dialogClosed && selectsHidden

                if (closed || performance.now() >= deadline) {
                    resolve(closed)
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($overlaysClosed)->toBeTrue();

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('refreshes available job classes on filter intent as retained jobs arrive', function (
    RetainedJobType $type,
    string $url,
): void {
    bindRetainedJobBrowserFixtures(
        jobCount: 1,
        matchingIndex: 1,
        type: $type,
    );
    config()->set('zenith.poll_interval', 200);

    $page = visit($url)
        ->click('button[aria-label="Filter jobs"]');
    $jobClassLabel = json_encode('Job class', JSON_THROW_ON_ERROR);
    $opened = $page->script(<<<JS
        () => {
            const label = Array.from(document.querySelectorAll('[role="dialog"] label'))
                .find((element) => element.textContent?.trim() === {$jobClassLabel})
            const trigger = label instanceof HTMLLabelElement
                ? document.getElementById(label.htmlFor)
                : null

            trigger?.click()
            window.__retainedJobCatalogIntentMarker = 'preserved'

            return trigger !== null
        }
    JS);

    expect($opened)->toBeTrue();

    $initialClasses = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const deadline = performance.now() + 10000

            function inspect() {
                const classes = Array.from(
                    document.querySelectorAll('[data-slot="select-item"]'),
                    (item) => item.textContent?.trim(),
                )

                if (classes.includes('RoutineImport')) {
                    resolve(classes)
                    return
                }

                if (performance.now() >= deadline) {
                    reject(new Error('Timed out waiting for the initial job filter catalog.'))
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($initialClasses)->toBe([
        'All job classes',
        'RoutineImport',
    ]);

    $page
        ->click('[data-slot="select-item"]:has-text("All job classes")')
        ->click('Done');

    bindRetainedJobBrowserFixtures(
        jobCount: 2,
        matchingIndex: 1,
        type: $type,
    );
    config()->set('zenith.poll_interval', 200);

    $beforeIntent = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            window.setTimeout(() => {
                const catalog = window.history.state?.page?.props?.filterCatalog

                if (!catalog) {
                    reject(new Error('The initial filter catalog disappeared during polling.'))
                    return
                }

                resolve(catalog.jobs.map((job) => job.label))
            }, 750)
        })
    JS);

    expect($beforeIntent)->toBe(['RoutineImport']);

    $page->click('button[aria-label="Filter jobs"]');
    $opened = $page->script(<<<JS
        () => {
            const label = Array.from(document.querySelectorAll('[role="dialog"] label'))
                .find((element) => element.textContent?.trim() === {$jobClassLabel})
            const trigger = label instanceof HTMLLabelElement
                ? document.getElementById(label.htmlFor)
                : null

            trigger?.click()

            return trigger !== null
        }
    JS);

    expect($opened)->toBeTrue();

    $result = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const deadline = performance.now() + 10000

            function inspect() {
                const classes = Array.from(
                    document.querySelectorAll('[data-slot="select-item"]'),
                    (item) => item.textContent?.trim(),
                )

                if (classes.includes('ProductionOnly')) {
                    resolve({
                        classes,
                        marker: window.__retainedJobCatalogIntentMarker,
                    })
                    return
                }

                if (performance.now() >= deadline) {
                    reject(new Error('Timed out waiting for the intent-refreshed job catalog.'))
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($result)->toBe([
        'classes' => [
            'All job classes',
            'ProductionOnly',
            'RoutineImport',
        ],
        'marker' => 'preserved',
    ]);

    $page
        ->click('[data-slot="select-item"]:has-text("All job classes")')
        ->click('Done');

    $overlaysClosed = $page->script(<<<'JS'
        () => new Promise((resolve) => {
            const deadline = performance.now() + 1000

            function inspect() {
                const overlays = Array.from(document.querySelectorAll(
                    '[data-slot="select-content"], [data-slot="dialog-content"]',
                ))
                const hidden = overlays.every((overlay) => {
                    const style = window.getComputedStyle(overlay)

                    return style.display === 'none'
                        || style.visibility === 'hidden'
                        || style.opacity === '0'
                })

                if (hidden || performance.now() >= deadline) {
                    resolve(hidden)
                    return
                }

                requestAnimationFrame(inspect)
            }

            inspect()
        })
    JS);

    expect($overlaysClosed)->toBeTrue();

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
})->with([
    'completed jobs' => [RetainedJobType::Completed, '/horizon/jobs/completed'],
    'failed jobs' => [RetainedJobType::Failed, '/horizon/failed'],
]);

it('keeps the filter dialog available when optional job catalogs are unavailable', function (): void {
    bindBrowserPageFixtures();

    [$jobs, $failedJobs] = visit([
        '/horizon/jobs/completed',
        '/horizon/failed',
    ]);

    foreach ([$jobs, $failedJobs] as $page) {
        $page
            ->assertEnabled('button[aria-label="Filter jobs"]')
            ->click('button[aria-label="Filter jobs"]')
            ->assertSee('Global job filters are currently unavailable.')
            ->assertMissing('[aria-label="Preparing job filters"]');

        $dialogSettled = $page->script(<<<'JS'
            () => new Promise((resolve) => {
                const dialog = document.querySelector('[data-slot="dialog-content"]')
                const overlay = document.querySelector('[data-slot="dialog-overlay"]')
                const deadline = performance.now() + 1000

                function inspect() {
                    const fullyVisible = [dialog, overlay].every(
                        (element) => element && getComputedStyle(element).opacity === '1',
                    )

                    if (fullyVisible || performance.now() >= deadline) {
                        resolve(fullyVisible)
                        return
                    }

                    requestAnimationFrame(inspect)
                }

                inspect()
            })
        JS);

        expect($dialogSettled)->toBeTrue();

        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs()
            ->assertNoAccessibilityIssues();
    }
});

it('sorts the loaded completed rows locally and re-sorts infinite-scroll additions', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures(
        matchingIndex: 5,
        type: RetainedJobType::Completed,
    );
    $cursor = app(JobsData::class)->page(JobListType::Completed, null)->next;

    expect($cursor)->toBeString();
    assert(is_string($cursor));

    $page = visit('/horizon/jobs/completed?starting_at='.urlencode($cursor))
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $beforeIds = $page->script(<<<'JS'
        () => {
            window.__zenithSortRequests = []
            const originalOpen = XMLHttpRequest.prototype.open

            XMLHttpRequest.prototype.open = function (...arguments_) {
                window.__zenithSortRequests.push({
                    method: arguments_[0],
                    url: arguments_[1],
                })

                return originalOpen.apply(this, arguments_)
            }

            return Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            )
        }
    JS);

    $page
        ->click('button[aria-label="Sort by Job ascending"]')
        ->assertQueryStringHas('sort', 'name')
        ->assertQueryStringHas('direction', 'asc')
        ->assertQueryStringHas('starting_at', $cursor)
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $localResult = $page->script(<<<'JS'
        () => ({
            ids: Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            ),
            requests: window.__zenithSortRequests,
            hasServerSortProp: Object.prototype.hasOwnProperty.call(
                window.history.state?.page?.props ?? {},
                'sort',
            ),
            hasServerDirectionProp: Object.prototype.hasOwnProperty.call(
                window.history.state?.page?.props ?? {},
                'direction',
            ),
        })
    JS);

    if (! is_array($localResult)) {
        throw new LogicException('Expected the local sort probe to resolve an object.');
    }

    expect($localResult['ids'])->toBe($beforeIds)
        ->and($localResult['requests'])->toBe([])
        ->and($localResult['hasServerSortProp'])->toBeFalse()
        ->and($localResult['hasServerDirectionProp'])->toBeFalse();

    $sortedResult = $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const rows = () => Array.from(
                document.querySelectorAll('table tbody:last-of-type tr'),
            )
            const timeout = window.setTimeout(
                () => finish(new Error('Timed out waiting for the next locally sorted page.')),
                10000,
            )
            const observer = new MutationObserver(inspect)

            function finish(error = null, value = null) {
                window.clearTimeout(timeout)
                observer.disconnect()

                if (error) {
                    reject(error)
                    return
                }

                resolve(value)
            }

            function inspect() {
                if (rows().length < 60) {
                    window.scrollTo(0, document.documentElement.scrollHeight)
                    return
                }

                const ids = rows().map((row) => {
                    const href = row.querySelector('a')?.getAttribute('href') ?? ''

                    return decodeURIComponent(href.split('/').at(-1) ?? '')
                })

                finish(null, {
                    rowCount: ids.length,
                    first: ids[0] ?? null,
                })
            }

            observer.observe(document.querySelector('main'), {
                childList: true,
                subtree: true,
            })
            window.scrollTo(0, document.documentElement.scrollHeight)
            inspect()
        })
    JS);

    if (! is_array($sortedResult)) {
        throw new LogicException('Expected the next locally sorted page probe to resolve an object.');
    }

    expect($sortedResult['rowCount'])->toBe(60)
        ->and($sortedResult['first'])->toBe($matchingId);

    $page
        ->assertPresent("a[href$=\"/{$matchingId}\"]")
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('sorts only the loaded failed rows without resetting the cursor or requesting data', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures(
        type: RetainedJobType::Failed,
    );

    $page = visit('/horizon/failed?starting_at=49')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $beforeIds = $page->script(<<<'JS'
        () => {
            window.__zenithSortRequests = 0
            const originalOpen = XMLHttpRequest.prototype.open

            XMLHttpRequest.prototype.open = function (...arguments_) {
                window.__zenithSortRequests++

                return originalOpen.apply(this, arguments_)
            }

            return Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            )
        }
    JS);

    $page
        ->click('button[aria-label="Sort by Job ascending"]')
        ->assertQueryStringHas('sort', 'name')
        ->assertQueryStringHas('direction', 'asc')
        ->assertQueryStringHas('starting_at', '49')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $result = $page->script(<<<'JS'
        () => ({
            ids: Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            ),
            requests: window.__zenithSortRequests,
        })
    JS);

    if (! is_array($result)) {
        throw new LogicException('Expected the failed jobs sort probe to resolve an object.');
    }

    expect($result['ids'])->toBe($beforeIds)
        ->and($result['requests'])->toBe(0);

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('shows newest queue activity first and sorts loaded rows without a request', function (): void {
    $matchingId = bindRetainedJobBrowserFixtures(
        matchingIndex: 5,
        type: RetainedJobType::Completed,
        allInQueue: true,
    );

    $page = visit('/horizon/queues/reports?tab=completed')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $beforeIds = $page->script(<<<'JS'
        () => {
            window.__zenithSortRequests = []
            const originalOpen = XMLHttpRequest.prototype.open

            XMLHttpRequest.prototype.open = function (...arguments_) {
                window.__zenithSortRequests.push({
                    method: arguments_[0],
                    url: arguments_[1],
                })

                return originalOpen.apply(this, arguments_)
            }

            return {
                ids: Array.from(
                    document.querySelectorAll('table tbody:last-of-type tr a'),
                    (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
                ),
                completedSort: document
                    .querySelector('table thead th:nth-child(3)')
                    ?.getAttribute('aria-sort') ?? null,
            }
        }
    JS);

    if (! is_array($beforeIds) || ! is_array($beforeIds['ids'] ?? null)) {
        throw new LogicException('Expected the queue activity probe to report the rendered ids.');
    }

    expect($beforeIds['ids'][0] ?? null)->toBe('completed-109')
        ->and($beforeIds['ids'][49] ?? null)->toBe('completed-060')
        ->and($beforeIds['completedSort'])->toBe('descending');

    $page
        ->click('button[aria-label="Sort by Job ascending"]')
        ->assertQueryStringHas('tab', 'completed')
        ->assertQueryStringHas('sort', 'name')
        ->assertQueryStringHas('direction', 'asc')
        ->assertQueryStringMissing('starting_at')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertMissing("a[href$=\"/{$matchingId}\"]");

    $result = $page->script(<<<'JS'
        () => ({
            ids: Array.from(
                document.querySelectorAll('table tbody:last-of-type tr a'),
                (link) => decodeURIComponent(link.getAttribute('href')?.split('/').at(-1) ?? ''),
            ),
            requests: window.__zenithSortRequests,
        })
    JS);

    if (! is_array($result)) {
        throw new LogicException('Expected the queue activity sort probe to resolve an object.');
    }

    expect($result['ids'])->toBe($beforeIds['ids'])
        ->and($result['requests'])->toBe([]);

    $page
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});
