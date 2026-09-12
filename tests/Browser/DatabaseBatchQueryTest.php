<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\Batches\DatabaseBatchQuery;
use DevactionLabs\Zenith\Jobs\JobsData;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('filters statuses and sorts the complete retained database batch set', function (): void {
    bindBrowserPageFixtures();
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');

    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    $migration = require __DIR__.'/../../database/migrations/2026_07_26_000000_create_zenith_batch_metadata_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new LogicException('Expected the batch metadata migration to define an up method.');
    }

    $migration->up();

    $longBatchName = 'Large export batch for every European customer and accounting period that must stay inside its table column';
    $rows = [];

    foreach (range(1, 110) as $index) {
        $isRetainedMatch = $index <= 60;
        $isOtherConnection = $index >= 66 && $index <= 70;
        $isPending = $isRetainedMatch ? $index <= 55 : $isOtherConnection;
        $isActivePending = $index === 1;
        $name = match (true) {
            $index === 1 => 'Aardvark retained priority batch',
            $index === 55 => $longBatchName,
            $isRetainedMatch => sprintf('Priority retained batch %03d', $index),
            $isOtherConnection => sprintf('Other connection batch %03d', $index),
            default => sprintf('Noise batch %03d', $index),
        };
        $options = match (true) {
            $isRetainedMatch => ['queue' => 'priority', 'connection' => 'redis'],
            $isOtherConnection => ['queue' => 'priority', 'connection' => 'database'],
            default => [],
        };

        $rows[] = [
            'id' => sprintf('batch-%03d', $index),
            'name' => $name,
            'total_jobs' => 10,
            'pending_jobs' => $isPending ? ($isActivePending ? 5 : 10) : 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize($options),
            'cancelled_at' => null,
            'created_at' => 1_784_281_000 + $index,
            'finished_at' => $isPending ? null : 1_784_281_100 + $index,
        ];
    }

    DB::table('job_batches')->insert($rows);

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        DB::connection(),
        'job_batches',
    );
    $jobs = app(JobRepository::class);

    app()->instance(BatchRepository::class, $repository);
    app()->instance(BatchesData::class, new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
        app(DatabaseBatchQuery::class),
    ));

    $page = visit('/horizon/batches')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertSee('Aardvark retained priority batch');

    $initialFirstRow = $page->script(<<<'JS'
        () => {
            const row = document.querySelector('table tbody:last-of-type tr')
            const progress = row?.querySelector('[role="progressbar"]')

            return {
                name: row?.querySelector('a[data-batch-id]')?.textContent?.trim() ?? null,
                progress: Number(progress?.getAttribute('aria-valuenow') ?? NaN),
            }
        }
    JS);

    expect($initialFirstRow)->toBe([
        'name' => 'Aardvark retained priority batch',
        'progress' => 50,
    ]);

    $page
        ->click('button[aria-label^="Filter batches"]')
        ->assertSee('Narrow retained batches by queue, connection, or creation time.');

    $longBatchNameJson = json_encode($longBatchName, JSON_THROW_ON_ERROR);
    $layout = $page->script(<<<JS
        () => {
            const link = Array.from(document.querySelectorAll('table tbody:last-of-type td:first-child a'))
                .find((candidate) => candidate.textContent?.trim() === {$longBatchNameJson})
            const progressHeader = document
                .querySelector('button[aria-label^="Sort by Progress"]')
                ?.closest('th')
            const style = link ? getComputedStyle(link) : null

            return {
                display: style?.display ?? null,
                overflow: style?.overflow ?? null,
                textOverflow: style?.textOverflow ?? null,
                whiteSpace: style?.whiteSpace ?? null,
                clientWidth: link?.clientWidth ?? null,
                scrollWidth: link?.scrollWidth ?? null,
                title: link?.getAttribute('title') ?? null,
                progressWidth: progressHeader?.getBoundingClientRect().width ?? null,
            }
        }
    JS);

    expect($layout)->toMatchArray([
        'display' => 'block',
        'overflow' => 'hidden',
        'textOverflow' => 'ellipsis',
        'whiteSpace' => 'nowrap',
        'title' => $longBatchName,
    ]);

    if (! is_array($layout) || ! is_numeric($layout['clientWidth'] ?? null)) {
        throw new LogicException('Expected the batch name layout probe to report its client width.');
    }

    expect($layout['scrollWidth'])->toBeGreaterThan($layout['clientWidth']);
    expect($layout['progressWidth'])->toBeLessThanOrEqual(132);

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

    $openFilterSelect('Queue');
    $page
        ->click('priority')
        ->waitForText('Other connection batch 070')
        ->assertSee($longBatchName)
        ->assertDontSee('Priority retained batch 060')
        ->assertQueryStringHas('queue', 'priority');

    $openFilterSelect('Connection');
    $page
        ->click('redis')
        ->assertDontSee('Other connection batch 070')
        ->assertQueryStringHas('connection', 'redis')
        ->click('Done');

    $statusTabs = $page->script(<<<'JS'
        () => Array.from(
            document.querySelectorAll(
                '[role="tablist"][aria-label="Batch status"] [role="tab"]',
            ),
        ).map((tab) => {
                const labelContainer = tab.cloneNode(true)
                labelContainer.querySelector('[data-slot="badge"]')?.remove()
                const label = labelContainer.textContent?.trim() ?? ''
                const count = tab.querySelector('[data-slot="badge"]')?.textContent?.trim() ?? ''

                return {
                    label,
                    count,
                    selected: tab.getAttribute('aria-selected') === 'true',
                }
            })
    JS);

    expect($statusTabs)->toBe([
        ['label' => 'Pending', 'count' => '55', 'selected' => true],
        ['label' => 'Complete', 'count' => '5', 'selected' => false],
        ['label' => 'Incomplete', 'count' => '0', 'selected' => false],
        ['label' => 'Cancelled', 'count' => '0', 'selected' => false],
    ]);

    $page
        ->click('[role="tablist"][aria-label="Batch status"] [role="tab"]:nth-child(2)')
        ->assertQueryStringHas('status', 'finished')
        ->assertSee('Priority retained batch 060')
        ->assertCount('table tbody:last-of-type tr', 5)
        ->click('[role="tablist"][aria-label="Batch status"] [role="tab"]:first-child')
        ->assertQueryStringHas('status', 'pending')
        ->assertDontSee('Priority retained batch 060')
        ->assertCount('table tbody:last-of-type tr', 50)
        ->assertSee('Aardvark retained priority batch')
        ->click('button[aria-label="Sort by Batch ascending"]')
        ->waitForText('Aardvark retained priority batch')
        ->assertQueryStringHas('sort', 'name')
        ->assertQueryStringHas('direction', 'asc');

    $visibleNames = $page->script(<<<'JS'
        () => Array.from(
            document.querySelectorAll('table tbody:last-of-type a[data-batch-id]'),
        ).map((link) => link.textContent?.trim() ?? '')
    JS);

    expect($visibleNames)
        ->toHaveCount(50);

    if (! is_array($visibleNames)) {
        throw new LogicException('Expected the visible batch names probe to resolve a list.');
    }

    expect(array_slice($visibleNames, 0, 3))->toBe([
        'Aardvark retained priority batch',
        $longBatchName,
        'Priority retained batch 002',
    ]);

    $page
        ->fill(
            'input[aria-label="Search batches by name or ID"]',
            'Priority retained batch 010',
        )
        ->assertQueryStringHas('query', 'Priority retained batch 010')
        ->assertCount('table tbody:last-of-type tr', 1);

    $clearSearchAlignment = $page->script(<<<'JS'
        () => {
            const button = document.querySelector('button[aria-label="Clear search"]')
            const icon = button?.querySelector('svg')

            if (! button || ! icon) {
                return null
            }

            const buttonRect = button.getBoundingClientRect()
            const iconRect = icon.getBoundingClientRect()

            return {
                horizontalOffset: Math.round(
                    iconRect.left + iconRect.width / 2
                        - (buttonRect.left + buttonRect.width / 2),
                ),
                verticalOffset: Math.round(
                    iconRect.top + iconRect.height / 2
                        - (buttonRect.top + buttonRect.height / 2),
                ),
            }
        }
    JS);

    expect($clearSearchAlignment)->toBe([
        'horizontalOffset' => 0,
        'verticalOffset' => 0,
    ]);

    $page
        ->click('button[aria-label="Clear search"]')
        ->assertValue('input[aria-label="Search batches by name or ID"]', '')
        ->assertQueryStringMissing('query')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('keeps source-backed batch queries while hiding destination filters before migration', function (): void {
    bindBrowserPageFixtures();
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');

    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    DB::table('job_batches')->insert([
        'id' => 'batch-before-migration',
        'name' => 'Queryable before migration',
        'total_jobs' => 10,
        'pending_jobs' => 5,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => 1_784_281_000,
        'finished_at' => null,
    ]);

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        DB::connection(),
        'job_batches',
    );
    $jobs = app(JobRepository::class);

    app()->instance(BatchRepository::class, $repository);
    app()->instance(BatchesData::class, new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
        app(DatabaseBatchQuery::class),
    ));

    expect(Schema::hasTable('zenith_batch_metadata'))->toBeFalse();

    $page = visit('/horizon/batches?queue=stale-default&connection=stale-connection')
        ->assertSee('Queryable before migration')
        ->assertPresent('input[aria-label="Search batches by name or ID"]')
        ->assertPresent('[role="tablist"][aria-label="Batch status"]')
        ->assertPresent('button[aria-label^="Sort by"]')
        ->click('button[aria-label^="Filter batches"]')
        ->assertSee(
            'Run the Zenith batch metadata migration to enable queue and connection attribution.',
        )
        ->assertSee('Created');
    $labels = $page->script(<<<'JS'
        () => Array.from(document.querySelectorAll('[role="dialog"] label'))
            .map((label) => label.textContent?.trim() ?? '')
        JS);

    expect($labels)->toBe(['Created']);

    $page
        ->click('Done')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});

it('truthfully hides exact query controls for an unsupported batch repository', function (): void {
    bindBrowserPageFixtures();

    $batch = horizonBatch(
        'batch-unsupported',
        name: 'Generic repository batch',
        pendingJobs: 1,
    );
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$batch],
            'batch-unsupported' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );
    $jobs = app(JobRepository::class);

    app()->instance(BatchRepository::class, $repository);
    app()->instance(BatchesData::class, new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
    ));

    visit('/horizon/batches')
        ->assertPresent('[role="alert"]')
        ->assertSee('Exact batch queries unavailable')
        ->assertSee(
            'Exact retained batch queries require Laravel\'s database batch repository.',
        )
        ->assertSee('Generic repository batch')
        ->assertMissing('input[aria-label="Search batches by name or ID"]')
        ->assertMissing('[role="tablist"][aria-label="Batch status"]')
        ->assertMissing('button[aria-label^="Filter batches"]')
        ->assertMissing('button[aria-label^="Sort by"]')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});
