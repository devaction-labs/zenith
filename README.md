# Zenith

**The operator console and orchestration layer for Laravel Horizon.**

Zenith is the DevAction Labs fork of [nckrtl/horizon-new-dawn](https://github.com/nckrtl/horizon-new-dawn), renamed and extended. The original authors retain copyright under the MIT license.

> [!IMPORTANT]
> Zenith is pre-1.0 software. Production use is supported only within
> the operating envelope documented below, and minor releases may contain
> documented breaking changes.

Zenith replaces Laravel Horizon's bundled interface with a package-owned React 19 and Inertia 3 application while keeping Horizon's authorization, repositories, metrics, and queue workers in charge.

The package reads Horizon data in PHP and sends structured page props through Inertia. It does not add a second general browser-facing API layer. Horizon's API routes remain available; where Zenith wraps a mutating handler, it preserves Horizon's authorization and adds only the safety or execution behavior documented below.

![Zenith dashboard showing queue health, workload, and Horizon instances](docs/images/dashboard.jpg)

## Beyond the original Horizon interface

Compared with Horizon's bundled interface, Zenith adds:

- full retained-source search for pending, completed, and silenced jobs by
  case-insensitive partial job class or exact retained ID, composable with exact
  job class, queue, connection, and pending-state filters;
- Horizon's exact failed-tag filtering and fast client-side sorting across every
  job row currently loaded by infinite scrolling;
- exact database-backed batch search, status tabs, queue and connection filters, creation ranges, counts, and server-side sorting;
- Horizon instance management, with controls to pause or continue individual local instances and terminate local instances;
- supervisor controls and detailed configuration views covering scaling, balancing, process limits, memory, timeouts, retries, backoff, and configuration warnings;
- queue management, including timed or indefinite pauses, Laravel 13.25 global pause/resume of every queue on every connection, clearing one or all queues, and retrying failures for a specific queue;
- individual and bulk cancellation of eligible pending jobs, while protecting batched jobs so they are cancelled through their batch;
- bulk and scoped failure recovery, with controls to retry or remove one failed job, retry or clear all failures, and retry failures by queue, monitored tag, or batch;
- batch management, including cancelling active batches, retrying failed batch jobs, clearing retained failures, and clearing finished batches.

### Experimental

These modules are under active development and tracked in the
[P0 · Correctness](https://github.com/devaction-labs/zenith/milestone/1)
milestone. Their APIs and storage may change, and several do not yet behave as
described; check the linked issues before relying on them:

- a Schedule page for Laravel scheduler events, with next-run times, overlap flags, and on-demand runs (schedules still live in application code);
- unique and encrypted job contracts plus downstream Bus chain steps on job detail;
- workflow DAGs with named steps, dependencies, cascade outputs, unique names, and cancel/retry from the dashboard;
- Signals, Relay (dispatch and await a result), Chunks, Backfills, queue budgets, and runtime dynamic cron rows. These run as Horizon jobs or cache/database state; they do not replace Horizon workers.

## Roadmap

Zenith is working toward parity with [Oban Pro and Oban Web](https://oban.pro): live metrics, durable workflows, dynamic crons, and cluster-wide concurrency control, built on Horizon and Laravel 13 primitives. Planned work is tracked as [GitHub issues](https://github.com/devaction-labs/zenith/issues) in four milestones; [#44](https://github.com/devaction-labs/zenith/issues/44) is the overview.

| Milestone | Focus |
| --- | --- |
| [P0 · Correctness](https://github.com/devaction-labs/zenith/milestone/1) | Finish and harden the experimental orchestration modules (workflows, signals, relay, chunks, backfills, queue budgets, dynamic crons). |
| [P1 · Telemetry](https://github.com/devaction-labs/zenith/milestone/2) | Event-driven telemetry: live throughput, wait and runtime percentiles, attempt history, and durable job history. |
| [P2 · Dashboard parity](https://github.com/devaction-labs/zenith/milestone/3) | Workflow graph, cron history and runtime editing, runtime scaling, multi-select bulk actions, and payload redaction. |
| [P3 · Engine](https://github.com/devaction-labs/zenith/milestone/4) | Global limits and partitions, ordered chains, recorded output, a transactional outbox, and a workflow lifeline. |

The orchestration modules live on the `feat/orchestration` branch and will reach `main` once their P0 issues close.

## Renamed from Horizon New Dawn

Zenith was previously developed as Horizon New Dawn. Every package identifier changed with the rename:

| Before | After |
| --- | --- |
| `devaction-labs/horizon-new-dawn` | `devaction-labs/zenith` |
| `DevactionLabs\HorizonNewDawn` | `DevactionLabs\Zenith` |
| `php artisan horizon-new-dawn:*` | `php artisan zenith:*` |
| `config/horizon-new-dawn.php` | `config/zenith.php` |
| `horizon-new-dawn.*` Gates | `zenith.*` Gates |
| `horizon_new_dawn_*` tables | `zenith_*` tables |
| `public/vendor/horizon-new-dawn/build` | `public/vendor/zenith/build` |

The previous name was never published to Packagist, so there is no automatic upgrade path. Applications that installed a pre-release build from source should run `php artisan zenith:install` and `php artisan migrate`, which creates the new `zenith_*` tables, rename any `horizon-new-dawn.*` Gate definitions, and drop the old `horizon_new_dawn_*` tables after copying any audit history they want to keep.

## Requirements

- PHP 8.5 or newer
- Laravel 13.23 or newer
- Laravel Horizon 5.46.0 or newer within the 5.x series

These floors are deliberate:

- PHP 8.5 is the lowest PHP version covered by the package's release matrix. Zenith does not claim compatibility with runtimes it does not continuously test.
- Laravel 13.23 is required by `pestphp/pest-plugin-laravel` 5. Laravel 12 is no longer part of the supported contract.
- Horizon 5.46.0 is the oldest Horizon release exercised by Zenith's full package suite, real Redis worker smoke test, and consuming-application browser checks. Older Horizon releases are not part of the supported contract.

Queue pausing is available throughout the supported Laravel 13 matrix. Pausing or resuming every queue at once (`Queue::pauseAll()` / `Queue::resumeAll()`) additionally requires Laravel 13.25 or newer; earlier versions hide only those global controls.

Standalone Redis 6.2 or newer and standalone Valkey 8 are supported with
Predis and PhpRedis. Redis Cluster is supported by copying Horizon source
sets into hash-tagged package keys before multi-key writes, so `ZDIFFSTORE`
does not cross slots. Leave extra memory headroom for those snapshots.

Use `maxmemory-policy noeviction` for the Redis or Valkey instance that stores
Horizon queues and Zenith's retained-job indexes, and provision enough memory
for the configured retention windows. An `allkeys-*` policy may silently remove
queue or index keys under pressure. With `noeviction`, writes fail visibly
instead; monitor memory use and command errors so capacity can be increased
before that happens. Leave headroom beyond the persistent retained indexes:
reconciliation, search, and filtered queries use short-lived Redis union and
intersection sorted sets, while pending-state requests may copy live queue
structures into temporary snapshots.

Zenith reads and mutates batches through the application's configured
`BatchRepository`, including Laravel's database and DynamoDB implementations.
Exact full-history batch search, status and creation filters, counts, and
sorting require Laravel's `DatabaseBatchRepository`. Queue and connection
filters, queue-attributed batch summaries, and queue-level batch retry also
require Zenith's metadata migration; those destination-dependent features are
hidden until the migration has run, while the source-column SQL features remain
available. With another repository, Zenith keeps generic batch browsing and
actions but hides the query controls it cannot make exact. This does not make
DynamoDB a Horizon queue backend: Horizon still requires Redis for queues and
supervisors. The exact SQL query path supports MariaDB, MySQL, PostgreSQL, and
SQLite; applications using another database driver receive the same truthful
generic fallback.

Bulk mutations run as package-owned queued jobs. The configured bulk-operation
connection must therefore use an asynchronous queue driver that is processed by
Horizon. Zenith refuses these operations on `sync` and `null` drivers.
Retry all and Clear all on the failed-jobs page use Horizon's global retained
failure set at the moment the coordinator job starts, not the current filter
result. The HTTP request only authorizes, validates the bulk connection, and
enqueues one coordinator; workers process every eligible retained target in
bounded snapshot chunks with safe continuations.

## Installation

Install and configure Laravel Horizon in the host application first. Then install Zenith and publish its compiled assets:

> [!NOTE]
> Zenith is not on Packagist yet. Until the first tagged release, add the
> repository to the host application's `composer.json` and require
> `devaction-labs/zenith:dev-main` instead of `^0.1.0`:
>
> ```json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/devaction-labs/zenith" }
> ]
> ```

```bash
composer require devaction-labs/zenith:^0.1.0
php artisan zenith:install
php artisan migrate
```

The installer publishes configuration and compiled assets, and appends
`@php artisan zenith:assets --ansi` to the root application's
`scripts.post-autoload-dump` so later `composer install` and `composer update`
runs refresh published assets automatically. Use `--no-composer-hook` to skip
that edit. Pass `--force` only when you intentionally want to republish an
otherwise current build.

Visit the host application's existing Horizon path, which is `/horizon` by default. Zenith honors Horizon's configured path and domain and uses Horizon's existing authorization callback and middleware.

After the first install, package updates usually need no extra asset step:
Composer’s `post-autoload-dump` runs `zenith:assets` and replaces the
package-owned `public/vendor/zenith/build` directory when the published
build is missing, partial, stale, or contains files not present in the current
package build. An exact match is a no-op. Republish config or re-check
production prerequisites with the full installer:

```bash
php artisan zenith:install
php artisan migrate
```

Publish or refresh assets alone with:

```bash
php artisan zenith:assets
```

If Composer scripts are disabled (`--no-scripts`) or the hook was not added,
run `php artisan zenith:assets` after installs and updates. No Node.js
or frontend build is required in the consuming application.

## Production deployment

Zenith's interface includes actions that mutate Horizon, Redis, and batch
state. Validate a release against a production-like environment and backup or
retention policy before exposing those actions to operators.

Zenith uses Horizon's existing `Horizon::auth` callback, normally backed by
the `viewHorizon` Gate, as the admission boundary. After that, optional Gates
can restrict mutations:

```php
Gate::define('zenith.pauseQueues', fn ($user) => $user->isAdmin());
Gate::define('zenith.clearQueues', fn ($user) => $user->isAdmin());
Gate::define('zenith.retryJobs', fn ($user) => $user->isOperator());
Gate::define('zenith.cancelJobs', fn ($user) => $user->isAdmin());
Gate::define('zenith.manageInstances', fn ($user) => $user->isAdmin());
Gate::define('zenith.manageMonitoring', fn ($user) => $user->isOperator());
Gate::define('zenith.manageBatches', fn ($user) => $user->isAdmin());
```

Undefined Gates remain allowed for anyone Horizon already admitted. Successful
mutations are written to the application log and, after migrate, to
`zenith_audit_events` (visible at `/horizon/audit`).

Choose a durable, asynchronous connection and a queue that is consumed by
Horizon for bulk operations:

```php
'bulk_operations' => [
    'connection' => 'redis',
    'queue' => 'horizon-maintenance',
],
```

Bulk-operation jobs inherit the consuming Horizon supervisor's timeout. Keep
that timeout several seconds below the selected Redis connection's
`retry_after`, and configure the process manager's shutdown allowance above the
longest permitted job runtime. Each continuation performs a fixed-size page of
work and requeues itself until the point-in-time target snapshot is exhausted,
so supervisor timeouts need only cover one chunk plus snapshot bookkeeping.

A successful dispatch response confirms only that the bulk coordinator entered
the queue. Temporary Redis snapshot state is namespaced and renewed while
continuations run; it is not limited by a one-hour runtime or item-count
ceiling. Abandoned state that is never renewed eventually expires, and a later
continuation then fails closed with an explicit missing-state error. Snapshot
members are acknowledged only after a terminal outcome so interrupted chunks
can be retried without silently skipping unprocessed targets. Completion and
failure records are written to the application log, and execution failures also
appear in Horizon's failed-jobs view for inspection and retry.

Global failed-job retry and clear actions are never rejected solely because the
retained count is large. New failures retained after the coordinator captures
its snapshot are outside the operation boundary and do not make the run
endless.

Repository-wide batch scans used by summaries, filter catalogs, and generic
fallback bulk preflights walk the complete retained history page-by-page with
no configurable total ceiling. Malformed or non-advancing repository pages
fail closed. Database-backed search, status and creation filters, counts,
sorting, and SQL batch-clear classification keep their optimized paths. Queue
and connection filters additionally require the package metadata migration.

Applications using Laravel's database batch repository should schedule
`queue:prune-batches` with retention options appropriate for that application.
Applications using Laravel's DynamoDB batch repository must configure the
repository's native TTL through `queue.batching.ttl_attribute` and
`queue.batching.ttl` instead; Laravel's batch-pruning command does not prune
DynamoDB batches.

Set Horizon's `trim.pending` visibility window longer than the longest delay or
release interval used by batch jobs. Otherwise Horizon may trim pending
metadata before a destructive preflight can see an active delayed job.

For applications that cache routes or configuration, use this update order:

```bash
php artisan optimize:clear
composer install --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

When Composer scripts run, `post-autoload-dump` refreshes Zenith assets via
`zenith:assets`. With `--no-scripts`, or when shipping immutable
artifacts that already include `public/vendor/zenith/build`, skip the
Artisan publish step on deploy nodes. Otherwise run
`php artisan zenith:assets` (or the full installer) on each node after
Composer. Publication replaces the whole package-owned
`public/vendor/zenith/build` tree from a validated staged copy, so a
request either sees the previous tree or the new one—not a mixed generation.
There can be a brief gap while the destination is swapped.

If you are adopting the retained-job filters in an existing production Horizon
deployment, you may optionally prebuild both request-reconciled indexes once
after deployment:

```bash
php artisan zenith:warm-batch-metadata
php artisan zenith:warm-retained-jobs
```

Run `zenith:warm-batch-metadata` only after the package migration is
present. The retained-job warm command does not require that SQL table. Neither
warm-up is required on every deploy. Zenith still reconciles newly retained
records on requests; the commands exist to keep the first operator request from
paying the initial full-history reconciliation cost.

Request reconciliation adds newly retained jobs immediately, while every exact
query intersects a point-in-time Horizon source snapshot so expired projection
members cannot appear in results. Removing stale projection members is
maintenance rather than a serving requirement. Long-lived, high-throughput
applications should therefore schedule `zenith:warm-retained-jobs`
outside peak traffic at a cadence appropriate for their Horizon retention and
throughput, using Laravel's `withoutOverlapping()` guard. This bounds package
index storage without making page correctness depend on the scheduler.
If another reconciliation already owns a retained-type synchronization lock,
the warm command fails instead of claiming that stale cleanup completed; rerun
it after that reconciliation finishes.

The projection stores immutable metadata and facet memberships for Horizon's
retained jobs, so its Redis memory use and first reconciliation cost scale with
the application's configured Horizon retention windows. Tune
Horizon's `trim` values for the history operators actually need, and monitor
Redis memory and command latency after enabling the filters. Pending-state
filters remain request-time rather than persistently cached. Each filtered
request atomically copies the relevant ready or reserved structure, or stores
the matching delayed score window, into short-lived snapshot keys before
scanning it in chunks. The snapshot TTL is renewed while it is read and the
keys are deleted afterward. This prevents workers from shifting live offsets
and silently omitting jobs, but its request work and transient memory scale
with the selected queues' current backlog. A released-state request may
temporarily hold both a ready-list copy and the due portion of the delayed set.
Leave Redis or Valkey headroom for that snapshot in addition to the persistent
projection and temporary query intersections.

Zenith excludes its configured Horizon route tree from the host application's
Inertia SSR gateway because the package intentionally ships a client-only
bundle. It also uses Laravel's Vite CSP nonce for its bootstrap script, package
Vite entry tags, and Inertia runtime styles.

Some React components use inline style attributes for runtime geometry. CSP
deployments must permit those attributes; policies with
`style-src-attr 'none'` are outside the supported production envelope.

After package or configuration changes, restart Horizon and any long-lived
application process such as Octane or FrankenPHP so workers do not retain old
code or configuration:

```bash
php artisan horizon:terminate
php artisan octane:reload
```

Use the command appropriate for the host runtime when Octane is not installed.
The built frontend explicitly targets Vite's `baseline-widely-available`
browser set.

To populate the metrics pages, schedule Horizon's snapshot command every five minutes in the host application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
```

Rollback by restoring the prior Composer lock file and application release,
running that release's installer, rebuilding Laravel's caches, and restarting
the long-lived processes. Redis Cluster remains outside the supported
production envelope.

## Reverting to the original Horizon interface

Remove Zenith, reinstall Horizon's resources, and clear the application's cached configuration and routes:

```bash
composer remove devaction-labs/zenith
php artisan horizon:install
php artisan optimize:clear
```

The original Horizon interface will then be available at the application's existing Horizon path. Current Horizon releases load their compiled interface assets directly from the `laravel/horizon` package, so the deprecated `horizon:publish` command is not required.

## Interface

Zenith provides dedicated Inertia routes for:

- the dashboard, system status, supervisors, workload, throughput, and wait times;
- pending, completed, silenced, and failed job lists with job detail pages;
- retrying one failed job or all failed jobs;
- monitored tags with completed and failed job views;
- job and queue metrics with historical snapshots;
- job batches, batch search, batch progress, failed jobs, and batch retry.

The interface uses a persistent responsive layout composed from shadcn/ui
primitives. It supports light, dark, and system themes; exact server-sorted
database batch tables; client-sorted job tables; Inertia-powered infinite
scrolling; optional automatic refreshes; responsive navigation; and Inertia
mutations with toast feedback. Generated Wayfinder routes are rebased at
runtime, so links and actions continue to work with a custom Horizon path or an
absolute domain URL.

On the pending, completed, and silenced Jobs pages, search examines Horizon's
complete retained source before the 50-row page is selected. A term matches any
job class that contains it case-insensitively, or the retained job whose ID
matches it exactly. Those alternatives are combined with OR, then intersected
with any active exact job class, queue, connection, and pending-state filters.
The Failed Jobs search remains Horizon's exact tag query.

Zenith maintains package-owned Redis projections of immutable job metadata
and reconciles them from Horizon on requests; it does not require an event
listener, scheduler, or background indexing process. Full-source search reuses
that projection and adds no persistent search-specific index. When a search
matches multiple class facets or an exact retained ID, Zenith creates only
short-lived Redis union and query keys, deletes them after the request, and
retains a short expiry as cleanup fallback.

Unfiltered list totals and time-window counts read Horizon's retained sorted
sets directly. Exact filter catalogs share one retained-source snapshot per
job type, expose only values still represented in that snapshot, and reuse a
job's cached immutable class, queue, connection, and tags when it moves between
pending and terminal history. The browser requests a catalog only when the
filter trigger is hovered, focused, or opened. Normal list polling excludes
catalogs, and successful catalog responses are reused for one configured poll
interval.

Partial job-class search unions every matching retained class. Redis union and
catalog work is split into internal bounded command chunks so no single command
receives an unbounded key list and retained job records are not fully
materialized. A term that matches every retained class uses the existing
projection directly. Intent-loaded exact-filter catalogs return every distinct
current job class, queue, and connection value, reading Redis catalog data in
internal bounded pages; the final option list is output-sized.

Pending rows retain Horizon's enqueue chronology while their ready, delayed,
released, or reserved state is resolved from a point-in-time snapshot of the
live queue structures.

Sortable job headers on the main job lists and queue activity views reorder
every row currently loaded in the browser. Sorting does not make another
request and is intentionally not a global retained-history sort. Infinite
scrolling still requests the next 50 rows in Horizon's retained order; after
those rows arrive, the combined loaded collection is sorted again. Numeric
nulls remain last in either direction. Pending jobs support Job and Queued;
completed and silenced jobs additionally support Completed and Runtime; failed
jobs support Job, Runtime, and Failed. Monitored-tag lists keep Horizon's
repository order.

Pending State remains an exact request-time server filter. Its badge can change
when a job moves between ready, reserved, and delayed structures or crosses its
release time. Each state-filter request uses one immutable, point-in-time queue
snapshot so a worker cannot make the scan itself omit members. Jobs explicitly
made available by Zenith are classified as Ready, matching their displayed
badge; naturally elapsed schedules remain Released.

For database-backed batches, Zenith stores one immutable queue and connection
snapshot per retained batch in `zenith_batch_metadata`. Explicit batch
options are preserved. When an older batch omitted an option, its value is
inferred once from the application's queue configuration when Zenith first
discovers that batch. The interface labels that provenance because a default
that changed before first discovery cannot prove the batch's historical
physical destination. Once captured, the snapshot does not change when
configuration changes. Lifecycle counts and status always come directly from
Laravel's `job_batches` table.

Horizon API routes remain available under Horizon's existing authorization
boundary. Read responses keep Horizon's existing contract. Monitoring
mutations also apply Zenith's reserved-key and currently-monitored tag guards,
while bulk operations retain their bounded asynchronous execution safeguards.
Unsupported legacy UI paths return `404` instead of silently falling back to
Horizon's Vue application.

## Configuration

Publish the configuration when you need to change defaults:

```bash
php artisan vendor:publish --tag=zenith-config
```

```php
return [
    'poll_interval' => 5000,
    'job_navigation_breakdown' => false,
    'job_payload_allowed_classes' => [],
    'bulk_operations' => [
        'connection' => null,
        'queue' => null,
    ],
];
```

Set `job_navigation_breakdown` to `true` to show Pending, Failed, Completed,
and Silenced links below the Jobs navigation item. It is hidden by default;
the Jobs item itself always links to Pending.

`job_payload_allowed_classes` is a legacy compatibility escape hatch for
releasing unique-job locks from serialized queue commands. Keep it empty unless
an exact trusted job class must be instantiated; the safe default relies on
Laravel's serialized queue context and never instantiates arbitrary payload
classes. PHP deserialization may invoke lifecycle methods such as `__wakeup`
and `__destruct`, so allow only side-effect-free classes controlled by the
application.

## Development

```bash
composer install
bun install
php vendor/bin/testbench workbench:build
composer quality
bun run format:check
bun run lint
bun run test
bun run typecheck
bun run build
```

Regenerate typed Horizon route helpers after route changes:

```bash
bun run wayfinder:generate
```

The package includes an Orchestra Workbench application with deterministic successful and failing queue jobs for exercising the interface.

## License

Zenith is open-source software licensed under the MIT license.
