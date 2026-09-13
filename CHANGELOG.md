# Changelog

All notable changes to Zenith will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-09-13

### Added

- A `connection` dimension on the telemetry recorder (`TelemetryDimension::Connection`, `TelemetryGroupBy::Connection`), so Live Metrics can be grouped or filtered by the queue connection a job ran on. Jobs dispatched through `deferred` and `background` connections — invisible to Horizon's own supervisor pages — are already recorded today, since both drivers fire the same `JobProcessing`/`JobAttempted` events the recorder listens to; this makes that execution data queryable on its own axis instead of blending into the other dimensions.

## [0.5.0] - 2026-09-13

### Added

- `zenith.queue_failover.ignored_connections` lets an operator acknowledge a connection deliberately configured with a bypass-prone driver (`deferred`, `failover`, `background`), excluding it from the "Jobs may be bypassing Horizon" dashboard banner and its recent-failover counters. Defaults to empty, so an unused stub connection (Laravel 13 ships `deferred` and `failover` by default) still surfaces until acknowledged.

## [0.4.0] - 2026-09-12

This release closes out the remaining Oban-parity roadmap: event-driven telemetry, durable history, workflow observability and resilience, bulk job actions, an engine layer of job-class attributes, and a transactional outbox all land together.

### Added

- An opt-in, event-driven telemetry recorder (`zenith.telemetry.enabled`) that listens to Laravel's own queue events instead of Horizon's snapshots, feeding live per-second/minute/5-minute throughput and wait/runtime percentile charts on the dashboard, per-attempt error history on job and failed-job detail, and an in-flight "Executing" page with a per-node breakdown. Every telemetry-backed screen falls back to the existing Horizon-snapshot behavior while the recorder is disabled.
- Durable job history: an opt-in `zenith_job_history` table records one row per terminal job attempt (class, queue, connection, status, attempts, runtime, tags, a short error summary) with no raw payload or exception trace, governed by ordered, configurable retention rules and pruned with the new `zenith:prune-history` command.
- A workflow DAG explorer: the Workflows page renders a step graph alongside the existing table, with a lifeline (`zenith:repair-workflows`) that finds steps stuck past their `#[Timeout]` and either re-dispatches or fails them, plus a graceful-interruption path and a "Stale" badge for steps caught by it.
- Schedule run history and a global scheduler pause: every scheduled event's recent runs (exit code, duration, output tail) are recorded and shown on the Schedule page, and the whole scheduler can be paused and resumed from the dashboard.
- Dynamic, runtime-editable cron rows: create, update, delete, and pause individual cron entries from the Schedule page; `DynamicSchedule::tick()` dispatches each due, unpaused row through an allowlisted job class.
- Runtime supervisor scaling: a `manageInstances`-gated control on the supervisor detail page dispatches Horizon's `Scale` command, bounded by the supervisor's own configured process range.
- Multi-select bulk actions on job tables: a tri-state checkbox column with "select page" and "select all loaded" modes drives bulk retry and cancel across the pending, completed, silenced, and failed lists.
- Retrying completed and cancelled jobs: a retained completed or silenced job's payload can be re-dispatched as a new job, gated by `retryJobs` and recorded through the existing audit-mutation middleware, with eligibility checks for missing payloads or command classes.
- Laravel 13 job attributes on job detail: `Tries`, `Backoff`, `Timeout`, `FailOnTimeout`, `MaxExceptions`, `UniqueFor`, `DebounceFor`, `Queue`, `Connection`, `Delay`, `WithoutRelations`, `DeleteWhenMissingModels`, and the `Queue::route()` destination are read via reflection and shown without instantiating the job.
- A Horizon-bypass warning: dashboard and queues-page banners, plus a per-row badge, flag connections using Laravel's `failover`, `deferred`, or `background` drivers and recent `QueueFailedOver` occurrences that Horizon (and therefore Zenith) cannot see.
- Payload redaction: job, failed-job, and workflow payloads mask keys matching the configured `zenith.redact_payload_keys` patterns before reaching Inertia, with a `Zenith::redactPayloadUsing()` hook to fully replace the logic.
- A `tag:` search qualifier and facet across every job list (pending, completed, silenced, and failed), extending the facet failed jobs already had; opt-in per-argument search was evaluated and deliberately deferred, with the reasoning recorded in `docs/architecture.md`.
- A per-user refresh-rate selector (1s/2s/5s/15s/off), replacing the fixed poll interval, persisted in `localStorage` and defaulting to the server-configured value; `g d` / `g j` / `g f` navigation shortcuts, `/` to focus the page's search box, and `?` for a shortcuts help dialog.
- An engine layer of job-class attributes: `#[GlobalLimit]`, `#[RateLimit]`, and `#[Partition]` enforce concurrency and rate limits through the existing queue-budget primitives; `#[ChainBy]` (or a fluent `chainKey()`) runs jobs sharing a key in strict order across dispatches, connections, and queues; `#[Recorded]` captures a job's return value, keyed by its queue UUID.
- Anti-starvation alerting: `QueueStarvationAlert` flags a queue whose oldest ready job has aged past `zenith.starvation.threshold_seconds`, deliberately alert-only rather than reprioritizing jobs itself.
- Testing fakes for the orchestration primitives: `Workflow::fake()`, `Signal::fake()`, and `Relay::fake()` swap in isolated, assertable state, and `drainWorkflow()` runs a workflow's claimed steps and compensations to a terminal status in-process without a real queue worker.
- A transactional outbox for job dispatch: `Outbox::dispatch()` writes a row inside the caller's own transaction, and delivery happens exclusively through a scheduled `zenith:relay-outbox` sweep, closing the crash window between a commit and the push; `Outbox::backlog()` exposes pending-row count and oldest-pending age as autoscaling gauges.
- `php artisan zenith:export-metrics` prints Prometheus text-format queue depth, wait time, and throughput samples for external autoscalers (KEDA, the Kubernetes HPA) without opening a new HTTP route.
- An AI-assisted failure-explanation hook: `Zenith::explainFailureUsing(callable)` lets a host register an explanation callback backed by whatever client it already depends on; the failed-job detail page shows an "Explain this failure" action only when a callback is registered.
- Two RFC decisions recorded in `docs/architecture.md`: fetch-time gating for limits stays a no-go for now (Horizon's fetch path has no supported extension point), while queue-depth and throughput metrics are exported through a CLI command rather than a new browser-facing endpoint.

### Changed

- Routes now authorize through `AuthorizeHorizonAbility::for()`, a static method on the middleware class, instead of a package-declared global `horizonAbility()` helper function.
- `routes/zenith.php` mutation routes are covered by a feature test that proves every one of them rejects a forged cross-origin request and accepts a same-origin or token-carrying one.

### Fixed

- Stopped a Playwright 1.63+ browser test from silently re-running its script probe from scratch every second and losing events to discarded earlier attempts, by running the probe's script exactly once with the full timeout applied to itself.

## [0.3.0] - 2026-09-12

### Added

- Pull requests from this repository enable auto-merge with a merge commit as soon as the aggregate `CI` check passes, through an Auto Merge job that uses the organization `GH_PAT` secret so the merge triggers the follow-up workflows. Draft pull requests are skipped.
- Job composition on job detail: unique and encrypted contracts plus downstream Bus chain steps from the retained payload.
- Durable workflow DAGs (`WorkflowDefinition`) with named steps, dependencies validated at definition time (unknown names, self-dependencies, and cycles are rejected before anything is persisted), cascade outputs, nested sub-workflows, and compensation steps that undo completed work in reverse order when a later step fails. A step that throws is retried with its own queue attributes (`#[Tries]`, `#[Backoff]`, `#[Timeout]`) before the workflow fails; fan-in dispatch is claimed atomically so a step with several dependencies runs exactly once. Unique workflows can run again once a previous run has finished. The Workflows page shows nested children, and cancel/retry work from the dashboard.
- Signals and Relay: `Signal::await()` and `Relay::await()` release the current queue job while waiting instead of blocking a worker, and redeliver it later; declare a `retryUntil()` deadline on jobs that wait so releases never exhaust a fixed `$tries`. Outside a queued job, `Relay::await()` really waits for the result — over Redis `BLPOP` when available, otherwise polling with backoff — and propagates the relayed job's failure.
- Chunks: `ChunkBuffer` batches items atomically under a cache lock and flushes a chunk once it reaches its size or its timeout elapses, whichever comes first.
- Backfills: `Backfill::make()` accepts an invokable class name and queues each page as its own job, so a page failure can be retried without re-running earlier pages. A backfill built from a closure still runs its pages in-process, because Laravel's sync-queue serialization cannot carry a closure's captured state across jobs.
- Queue budgets: `QueueBudget` and `EnforceQueueBudget` now enforce rate limits and concurrency slots with Laravel's atomic cache locks, so the limit holds under concurrent workers and a throttled job's slot is released even when it throws.
- Dynamic cron rows are now executed: `DynamicSchedule::tick()` dispatches each due, unpaused row at most once per minute, and the Schedule page lists them next to Laravel's own scheduler events as runtime-editable.

## [0.2.0] - 2026-09-12

First release under the Zenith name. Earlier versions were published as Horizon New Dawn.

### Changed

- **Breaking:** Renamed the package from Horizon New Dawn to Zenith: `devaction-labs/zenith`, the `DevactionLabs\Zenith` namespace, `zenith:*` Artisan commands, `config/zenith.php`, `zenith.*` Gates and route names, `zenith_*` tables, `zenith:` cache and Redis key prefixes, and the `public/vendor/zenith/build` asset path.
- Raised the supported runtime floors to PHP 8.5 and Laravel 13.23, required by Pest 5 and `pestphp/pest-plugin-laravel` 5. Laravel 12 and PHP 8.3/8.4 are no longer part of the supported contract.
- Upgraded the test suite to Pest 5 and PHPUnit 13, and added `pestphp/pest-plugin-phpstan` so PHPStan understands Pest's test API.
- Installed Rector 2 with the community Laravel plugin (`driftingly/rector-laravel`) for composer-based Laravel upgrades.
- Upgraded Vite+ to 0.3.1 (Vitest 4.1.11) and regenerated the frontend lockfile within the declared ranges, so `bun audit` reports no vulnerabilities.
- Split the dependency audit into its own CI job, added the aggregate `CI` status check used by branch protection, and limited push builds to `main`.

### Added

- A dedicated Release workflow runs after the Tests workflow succeeds on `main`. The `release:patch`, `release:minor`, or `release:major` label of the merged pull request picks the next version; the workflow tags it and publishes a GitHub release whose notes come from the version's CHANGELOG section. Manual dispatch releases an explicit version after checking that the commit's CI check passed.
- Local Pest runs use test impact analysis and can fetch the dependency graph published by the new TIA Baseline workflow; `composer test:full` runs the whole suite, and CI always does.
- Rebranded the fork to DevAction Labs (`devaction-labs/zenith`, `DevactionLabs\Zenith`).
- Added Laravel 13.25 global queue pause and resume (`Queue::pauseAll()` / `resumeAll()`), gated when the framework methods are missing, with a shell banner and Queues actions that leave individually paused queues paused after a global resume.
- Warned at install time when Redis Cluster connections are configured.
- Added optional per-action Gates (`zenith.pauseQueues`, `clearQueues`, `retryJobs`, `cancelJobs`, `manageInstances`, `manageMonitoring`, `manageBatches`) on top of Horizon auth. Undefined gates remain allowed for anyone Horizon already admitted.
- Recorded successful mutations to the application log and `zenith_audit_events`, with an Audit page.
- Surfaced Laravel `Queue::route()` class routes and `Queue::forward()` destinations on queue detail.
- Added retained-source search on monitored-tag job lists (completed and failed).
- Copied Horizon source sets into hash-tagged keys on Redis Cluster so retained-job indexes no longer issue CROSSSLOT `ZDIFFSTORE` commands.

### Fixed

- Kept `Queue::route()` class routes visible on Laravel releases that predate `Queue::forward()`; forwarding destinations appear only where the framework supports them.
- Raised the memory limit of the Composer test scripts so the package suite no longer exhausts PHP's default 128M CLI limit.

## [0.1.5] - 2026-07-30

### Fixed

- Stop tab selection from deliberately moving the document viewport, scrolling the desktop tab strip, or following queue activity anchors; route-backed tabs preserve scroll, including mobile select-close handling.
- Size each active job, queue, batch, metrics, and monitoring tab panel to its own content instead of retaining another tab's height.

## [0.1.4] - 2026-07-30

### Added

- Added page-by-page retained batch scans and queued bulk operations that walk complete history without a configurable total ceiling, with fail-closed handling for malformed repository pages and incomplete-result states for partial scans.
- Added production deployment guidance for asynchronous maintenance queues, cached configuration and routes, multi-node asset publication, long-lived process restarts, CSP nonces, Horizon snapshots, and rollback.
- Added a request-reconciled Redis job index for case-insensitive partial-class or exact retained-ID search and exact job class, queue, connection, pending-state, and failed-tag queries without a background indexing process or persistent search-specific index.
- Bound job-class catalog pipelines, intent-loaded exact-filter options, and partial-search fan-out; repair exact job, queue, connection, and pending-target partitions; and report overly broad catalogs or searches instead of exhausting a PHP worker or silently truncating results.
- Added client-side job sorting across every row currently loaded by infinite scrolling.
- Added an optional `zenith:warm-retained-jobs` Artisan command to prebuild retained-job Redis indexes during production adoption.
- Added database-backed full-source batch search, status and creation filters, status counts, and stable server-side sorting before 50-row pagination.
- Added an optional batch destination metadata migration and warm command for exact queue and connection attribution.
- Added real-service retained-job compatibility coverage for standalone Redis 6.2 and 7 and Valkey 8 with PhpRedis, Predis 3, and a dedicated Predis 2 run.

### Changed

- Dispatch potentially long-running bulk mutations to a configured asynchronous queue and reject synchronous or null queue drivers.
- Let queued bulk operations inherit the consuming Horizon supervisor's timeout instead of imposing a package-level timeout.
- Require serialized, resumable releases to be started manually with an explicit semantic version after the complete compatibility and test matrix passes, with third-party actions pinned to immutable revisions.
- Exclude the package's client-only route tree from a host application's Inertia SSR gateway without disabling SSR for unrelated host routes when Horizon owns the root path.
- Refresh complete but stale published frontend builds during a normal install, retain the prior asset generation for in-flight clients and rollback, prune older generations, and clean abandoned staging directories safely.
- Apply every visible paginated job search, exact filter, and database-batch query control to the complete queryable source, and omit controls that cannot be supported exactly.
- Preserve Horizon's retained pagination order while re-sorting the combined loaded job rows whenever infinite scrolling appends a page.
- Remove loaded-page-only filtering and sorting from monitored-tag job tables, retaining Horizon's repository order until exact full-source tag queries are available.
- Capture immutable batch queue and connection snapshots once, inferring omitted historical defaults from the configuration active at first discovery and labeling that provenance in the interface.
- Hide destination-dependent batch filters, summaries, and actions until the metadata migration is available while keeping source-column SQL queries enabled.
- Resolve expensive Inertia list props lazily and memoize each requested page so initial, deferred, and scroll requests do not duplicate repository work.
- Load job filter catalogs only on filter hover, focus, or open; keep them out of normal list polling; share one source snapshot across catalogs; reuse immutable metadata across lifecycle transitions; and read unfiltered counts directly from Horizon.
- Keep stale retained-job cleanup off serving requests while exact queries intersect an atomic Horizon source snapshot; expose the warm command for periodic storage hygiene.
- Document retained-job indexing's Redis 6.2+ standalone requirement because reconciliation uses `ZDIFFSTORE`.

### Fixed

- Allow first boot with cached host configuration when the package configuration has not been published yet.
- Preserve package polling, server-side scan-cache, and recent-failure defaults when a cached host configuration predates the package.
- Apply queued bulk-operation safeguards to Horizon's preserved batch retry API.
- Boot safely with custom Inertia SSR gateways that do not support path exclusions.
- Bundle toast styles instead of injecting an unnonced runtime stylesheet under strict content security policies.
- Keep forward-only infinite-scroll continuation cursors out of reloadable browser URLs.
- Make automatic refresh authoritative at the first page so removed or expired Horizon records disappear instead of accumulating through additive merges.
- Follow Horizon's page-one refresh model after infinite scrolling loads history: poll only freshness and summary props, keep the loaded window stable, and return to the current first page through the “new entries” action.
- Remove package-owned infinite-scroll loading and row-reconciliation fallbacks while Inertia owns the rendered collection, boundary detection, reset, and loading lifecycle.
- Keep overlapping native infinite-scroll requests cancellable instead of rejecting them after Inertia has entered its loading state.
- Keep the Jobs filter affordance stable while its optional catalog loads or is unavailable, including a path to clear active filters.
- Keep intent-loaded job-filter requests isolated from same-page searches and polling while cancelling them when their page unmounts.
- Start retained-job catalog scans with the client-specific cursor required by PhpRedis and Predis.
- Reconcile source growth incrementally without deleting valid indexed work, and fail closed under sustained churn without rebuilding on ordinary drift.
- Let fresh requests reuse an exact retained-job index without taking its synchronization lock or rotating its read-fencing revision.
- Make forced retained-job maintenance fail honestly on lock contention and prune empty facet catalogs even when stale job metadata has already expired.
- Keep debounced job searches and rapid multi-filter changes in one synchronized draft so a delayed request cannot undo a newer selection.
- Keep sidebar navigation counters semantically muted across normal, active, and hover states.
- Rebuild incomplete retained-job projection and catalog indexes under the synchronization lock before failing closed.
- Trim expired Horizon source references before retained-index synchronization so expired job hashes cannot cause a permanent rebuild loop.
- Fence filtered retained-index reads across concurrent synchronization and keep facet catalog reads non-mutating.
- Snapshot mutable ready, reserved, and delayed queue structures atomically while applying pending-state filters, renew the short-lived snapshot during chunked reads, and fail closed if it expires.
- Classify jobs explicitly made available by Zenith as Ready in both filters and displayed state while retaining Released for naturally elapsed schedules.
- Apply queue-activity job sorting to every currently loaded row, and expose local batch-detail job sorting only when the full non-paginated list is available.
- Scan only the two Redis hash fields needed to determine bulk failed-job retryability instead of hydrating full payload, exception, and context records.
- Keep global failed-job retry and clear actions available regardless of Horizon's unfiltered retained count, dispatching them as bounded asynchronous bulk operations instead of rejecting large scopes before dispatch.
- Prevent retained batch scans from reporting incomplete lower bounds as exact totals or enabling destructive actions.
- Avoid instantiating arbitrary classes while decoding serialized queued-job payloads.
- Propagate Laravel's Vite CSP nonce to package scripts and Inertia's runtime-injected styles.
- Pin the patched `brace-expansion` transitive release used by the frontend build toolchain.
- Bind batch continuation cursors to the complete active filter and sort signature so stale cursors cannot skip rows after a query change.

## [0.1.3] - 2026-07-24

### Added

- Added storage-agnostic batch browsing, filtering, counts, queue summaries, and actions through Laravel's configured batch repository, including DynamoDB-backed batch metadata.
- Added accessible collapsible JSON payloads and exception context with copy controls.

### Changed

- Show `Pausing` and `Continuing` states immediately for Horizon instances and supervisors, mark managed supervisors while an instance pauses, and rely on normal automatic refresh or temporary polling when automatic refresh is disabled.
- Populate batch queue and connection filters from the complete retained batch history, refresh metrics automatically, and present runtimes, waits, and backoff values consistently.

### Fixed

- Keep JSON expand and collapse controls anchored in the viewport while their payload content changes height.
- Keep Inertia infinite-scroll results, URL-backed filters, and pagination cursors stable while polling or when retained Horizon records disappear.
- Make bulk job and batch clearing and retries scan the complete retained source safely without skipping entries, duplicating work, or acting on ineligible records.
- Guard monitoring mutations from reserved Horizon keys, preserve correct failed and pending job links and states, and support slash-bearing supervisor names and metric identifiers.

## [0.1.2] - 2026-07-23

### Added

- Added live autoscaling indicators to supervisor rows, including predicted scale-up, scale-down, and idle states with complete animation cycles across target changes.
- Added an action to make an individual delayed job available to workers immediately while preserving its original scheduling metadata.

### Changed

- Distinguish ready, delayed, released, and reserved pending jobs using their effective schedule, and update their displayed state when a scheduled release time passes.
- Order pending jobs by effective availability across the complete retained set, and paginate completed, silenced, failed, and tagged failed jobs consistently from oldest to newest.

### Fixed

- Prevent retry attempts from inheriting stale scheduling state from the original failed job.
- Refresh immediately when automatic loading is enabled and reconcile polling resets without losing loaded infinite-scroll results or query state.
- Finish the active autoscaling indicator cycle before changing direction or returning to its neutral state.

## [0.1.1] - 2026-07-23

### Added

- Added a compatibility matrix covering PHP 8.3 through 8.5, Laravel 12.38 through 13, and Horizon 5.46 through the latest compatible 5.x release.
- Added a Redis-backed smoke test that starts a real Horizon supervisor and verifies both successful and failed job processing.

### Changed

- Set the supported runtime floors to PHP 8.3, Laravel 12.38, and Horizon 5.46, and removed end-of-life Laravel 11 from the supported matrix.
- Hide unsupported queue pause controls on Laravel 12.38 through 12.40.1 while keeping retry and clear actions available.
- Preserve queue-detail URLs while loading additional activity rows.
- Document why each version floor exists and why Redis Cluster is not yet supported.

### Fixed

- Backfill the worker option expected by newer Laravel releases when Horizon 5.46 does not register it, allowing real Horizon workers to boot normally.
- Calculate dashboard queue runtime and throughput leaders from retained metric snapshots instead of relying on repository methods unavailable in Horizon 5.46.

[Unreleased]: https://github.com/devaction-labs/zenith/compare/0.3.0...HEAD
[0.3.0]: https://github.com/devaction-labs/zenith/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/devaction-labs/zenith/releases/tag/0.2.0
[0.1.5]: https://github.com/nckrtl/horizon-new-dawn/compare/0.1.4...0.1.5
[0.1.4]: https://github.com/nckrtl/horizon-new-dawn/compare/0.1.3...0.1.4
[0.1.3]: https://github.com/nckrtl/horizon-new-dawn/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/nckrtl/horizon-new-dawn/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/nckrtl/horizon-new-dawn/compare/0.1.0...0.1.1
