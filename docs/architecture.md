# Architecture

Zenith is an additive Laravel package that replaces Horizon's browser interface without replacing Horizon itself. Horizon remains responsible for queue workers, Redis storage, metrics, authorization, and its existing API routes.

## Design goals

- Render Horizon data through PHP and Inertia instead of adding a duplicate browser-facing API client.
- Reuse Horizon's contracts and repositories so data semantics stay aligned with the installed Horizon version.
- Preserve Horizon's route path, authentication middleware, authorization callback, and API route contract.
- Keep the package isolated from the host application's Inertia root view and frontend build.
- Use package-owned controllers and routes for the complete browser interface while retaining Horizon's API contract.

## Request lifecycle

During application booting, `ZenithServiceProvider` registers concrete routes under Horizon's configured path and domain:

```text
GET  /horizon/dashboard
GET  /horizon/jobs/{type}/{job?}
GET  /horizon/failed/{job?}
GET  /horizon/monitoring/{tag?}/{status?}
GET  /horizon/metrics -> /horizon/metrics/jobs
GET  /horizon/metrics/{type}/{slug?}
GET  /horizon/batches/{batch?}
POST and DELETE mutation routes
```

The route group uses Horizon's middleware group and `Authenticate` middleware,
followed by the package's Inertia middleware. `Authenticate` delegates to
Horizon's existing `Horizon::auth` callback, normally backed by the
`viewHorizon` Gate. This single Horizon boundary covers every page and
mutation. The group is registered before Horizon's browser catch-all, so
concrete Zenith routes own supported screens without shadowing Horizon API
routes.

Horizon still registers its catch-all home route. The provider binds Horizon's home controller to a package controller that returns `404`, preventing unsupported paths from falling through to the bundled Vue interface. Horizon API routes do not receive Zenith's Inertia middleware. Where Zenith wraps an API mutation, the wrapper changes only its safety or execution behavior: monitoring keeps the package's reserved-key and currently-monitored tag guards, while batch retries use the bounded asynchronous bulk-operation path. Horizon's authorization, existing read responses, and route contracts remain intact.

`HandleInertiaRequests` selects the package root view and shares the Horizon base URL, runtime status, host maintenance state, polling interval, interface feature flags, and flash messages with every Zenith page.

## Backend data flow

Thin controllers delegate Horizon reads and mutations to feature services and actions. Those classes depend on Horizon contracts where Horizon already exposes the required behavior:

```text
Horizon repositories and calculators
    -> Dashboard, Jobs, FailedJobs, Monitoring, Metrics, and Batches services
    -> Spatie Laravel Data objects
    -> Inertia page props
    -> React pages
```

Large job collections use Inertia scroll props and package query signatures.
Horizon remains the source of truth: a request-driven Redis projection copies
each retained source ID and score and indexes only immutable job class, queue,
and connection metadata. Reconciliation compares source IDs with the
projection and hydrates only new jobs. Serving reads accept stale projection
members and intersect a point-in-time Horizon source snapshot before applying
facets, so expired members cannot leak into results and normal source churn
never discards valid indexed work. Forced warm reconciliation removes stale
members for storage hygiene. A type-scoped unresolved set records retained
source references whose Horizon job hash no longer exists, so those references
are excluded honestly instead of forcing endless full rebuilds.

Full retained-source Jobs search reads the existing job-class catalog and
selects every class containing the term case-insensitively, plus a retained job
whose ID exactly equals the term. Those alternatives are unioned, then
intersected with the exact job class, queue, connection, pending-state, or
failed-tag constraints supplied by the active query. Search adds no persistent
index beyond the existing metadata and facet projection. Query-specific ID,
union, and final candidate sorted sets use randomized Redis keys, are deleted
after the query, and have a short TTL as cleanup fallback.

Single-valued job-class, queue, connection, and pending-target catalogs are
verified as exact, disjoint partitions of the retained projection before they
are exposed or used for catalog-dependent queries. The proof unions facet keys
in bounded groups and checks both directions against the projection; a
mismatch forces one locked rebuild and bounded retry. Partial searches select
every matching class facet and union those keys in bounded Redis command
chunks. Intent-loaded filter catalogs return every distinct current option,
reading catalog members in internal bounded pages without loading retained job
records. A term matching every retained class skips the search union and reads
the projection directly.

The job, queue, and connection catalogs share one inspectable source
snapshot instead of independently materializing retained history. Catalog
values are included only when their facet intersects that snapshot. Immutable
metadata is stored once per retained job and reused across lifecycle sources;
corrupt or undecodable cache entries fall back to Horizon repository hydration.
Unfiltered totals and period counts bypass the projection and read Horizon's
source sorted sets directly.

The combined exact query counts the complete inspectable result, selects at
most 51 IDs, and hydrates only the visible page. Pending-state filters intersect
that result with Laravel's live ready, delayed, and reserved queue structures.
An explicit Artisan warm command can force a one-time full reconciliation
across every retained source, but no Horizon lifecycle listener, scheduler, or
indexing daemon is required for correctness.

Before synchronization, Zenith invokes Horizon's own recent- or failed-job
trim operation. Horizon job hashes and their sorted-set references can otherwise
expire on slightly different schedules; trimming first prevents a missing hash
from forcing the package index into a permanent rebuild loop. Persistent and
filtered reads fence their result with source coverage, the synchronization
revision, and the active lock. Facet catalog reads never delete live facet keys,
so they cannot race a concurrent hydration.

A fresh request that proves the required source coverage and catalog structure
already exact reuses the index without acquiring the global synchronization
lock or changing that revision. Forced maintenance never takes this serving
shortcut: it fails on lock contention, removes stale projection members, and
prunes empty facet keys and catalog values even when their cached job metadata
has already expired.

Controllers pass expensive scroll pages as memoized Inertia callables so an
initial response, deferred-prop request, or scroll request resolves only the
data it asks for and never executes the same page query twice. Job filter
catalogs share a deferred navigation request rather than triggering separate
requests for each list prop.

The projection's storage and initial reconciliation work scale with Horizon's
retained history. Normal request reconciliation closes missing coverage but
does not synchronously prune stale projection members. The explicit
`zenith:warm-retained-jobs` command performs that cleanup and may be
scheduled with `withoutOverlapping()` to bound storage independently of serving
correctness. Pending-state filters do not persist a second lifecycle state that
could drift from Laravel's queue data. On each filtered request, one Lua
script per selected queue target atomically copies the ready or reserved
structure with `COPY`, or materializes the relevant delayed score window with
`ZRANGESTORE`, into random snapshot keys. Released snapshots capture both the
ready list and due delayed members in the same script. Chunk reads validate an
expected-count guard, renew the 120-second TTL, and delete the snapshots in a
`finally` path; expiry or mutation fails closed. This prevents live list or
sorted-set offsets from shifting during the scan. Its request work and
transient memory still scale with the selected backlog. Reconciliation proves
source coverage as `source - projection - unresolved`; stale projection members
are allowed because every query starts from the atomic source intersection.
Structural facet and catalog checks still detect corruption. The synchronizer
repairs missing coverage incrementally, rebuilds only structural corruption,
and fails closed after bounded attempts if concurrent growth prevents complete
coverage.

Job pagination preserves Horizon's retained ordering. Signed cursors bind the
job type, exact filter signature, retained score, ID, and offset. The browser
may sort every row loaded in the current infinite-scroll session by the visible
columns; that operation is local and sends no server request. When another
50-row page arrives in retained order, the combined loaded collection is
re-sorted. This is intentionally distinct from a global retained-history sort.

Pending ordering is not described as global execution FIFO: separate queues,
delayed migration, worker concurrency, and application node clocks do not
provide one global execution order. A state filter is evaluated against its
immutable request-time snapshot. Payloads explicitly made available by
Zenith are Ready, matching the schedule-clearing row projection, while naturally
elapsed schedules are Released.

When Laravel uses `DatabaseBatchRepository`, full search, exact status and
creation filters, counts, and stable sorting run directly against live
`job_batches` columns before selecting a 50-row page. Those source-column
features do not require a package table or metadata reconciliation.

A package migration separately creates `zenith_batch_metadata` for
features that need a durable queue or connection destination. Request-driven
reconciliation captures explicit batch options once and resolves omitted values
from the application's queue configuration at first discovery. The provenance
flags remain stored, inferred values are immutable, and the interface states
that an older batch's inferred destination is a first-discovery estimate rather
than dispatch-time proof. Queue and connection filters, queue-attributed
summaries, and queue-level batch retry all query this same snapshot. They are
hidden, and direct mutations fail closed, until the migration is present. Base
batch queries do not join the sidecar. Missing metadata is inserted in chunks;
stale cleanup runs only when row counts first prove stale records may exist.

Batch detail pending inventory snapshots the batch's attributed live Redis
ready, reserved, and delayed structures first (`pendingQueueEntries`), using
stored sidecar attribution when available, then explicit batch options, then
configured defaults. Matching IDs are hydrated with `JobRepository::getJobs` so
still-retained Horizon hashes remain inspectable; missing hashes become
queue-derived rows without unserializing application classes. Those queue-only
rows are non-inspectable (no detail links) and are managed only through
batch-level actions. The full retained pending-history scan runs only when the
live queue snapshot is unavailable or unsupported. Completed and failed batch
history remain retained-hash scans; the legacy pending retained scan paginates
without a hard 250 ceiling and stops on non-advancing cursors.

Other `BatchRepository` implementations retain generic repository-ordered
browsing and actions, but the interface hides unsupported query controls and
states the capability limit. Generic summaries, filter catalogs, and
repository-wide destructive preflights scan the complete retained history
page-by-page with no configurable total ceiling, classify or count
incrementally where possible, and fail closed on malformed or non-advancing
pages. Database-backed batch-clear classification uses uncapped, chunked SQL
candidates with per-candidate failed-job verification.

Potentially long-running bulk mutations are represented by package-owned queue
jobs. The HTTP layer validates that their configured connection is
asynchronous, dispatches one coordinator, and returns immediately with
background-processing feedback. It never enumerates the full target set in the
request. The coordinator captures a point-in-time snapshot of the relevant
Horizon Redis sorted set (or repository-native ID stream) into namespaced
temporary keys, then processes fixed-size pages and enqueues continuations that
carry only bounded scalar state such as the operation ID. Snapshot members are
removed only after a terminal outcome (successful mutation or permanent skip),
so a failed chunk with `tries = 1` can be retried without losing unprocessed
IDs. Practical idempotency for already-scheduled retries relies on failed-job
eligibility. Temporary keys use a long idle TTL that is renewed on every active
access; active or backlogged operations are not limited by total runtime or
target count. Abandoned state that is never renewed eventually expires and
subsequent continuations fail closed with an explicit missing-state error.
Live mutations to Horizon source sets cannot shift, skip, or endlessly extend
the captured boundary. Each bulk job has one attempt, Horizon tags, and a
completion log entry. Operations inherit the consuming Horizon supervisor's
timeout instead of imposing a package-level override.

Repository failures are caught at data boundaries and converted into explicit unavailable states. List previews omit sensitive payload and exception data. Detail pages expose normalized fields deliberately, never raw repository objects or Redis internals.

## Telemetry

Horizon's `MetricsRepository` snapshots throughput and runtime every five
minutes. That is enough for the trend charts under `/metrics`, but it cannot
answer "what is happening right now": live per-second throughput, wait/execution
percentiles, per-attempt error history, or which jobs are currently executing.
Answering those requires observing Laravel's queue events as they happen, which
is otherwise deliberately out of scope for this package (see "Backend data
flow" above). The telemetry recorder is the one place Zenith listens to queue
events instead of reading Horizon's repositories, and it is opt-in for that
reason: `zenith.telemetry.enabled` defaults to `false`, and every screen that
depends on it falls back to the Horizon-snapshot behavior described elsewhere
in this document when it is off.

### Decision: which events to observe

`Illuminate\Queue\Worker::process()` dispatches many events per attempt
(`JobProcessing`, `JobProcessed`, `JobFailed`, `JobReleased`,
`JobReleasedAfterException`, `JobExceptionOccurred`, `JobAttempted`), but
exactly one of them, `JobAttempted`, is guaranteed to fire once per attempt
from a `finally` block, after the job object's `hasFailed()` and
`isReleased()` state is final. The recorder listens to only three events:

- `JobProcessing` opens an in-memory timer (keyed by job UUID) on the
  `TelemetryEventSubscriber` instance, which is bound as a singleton so the
  same object observes both ends of an attempt within one worker process.
- `JobAttempted` closes that timer and classifies the outcome as `Failed`
  (`$job->hasFailed()`), `Released` (`$job->isReleased()`), or `Processed`
  (neither) — in that order, since a job can be released without an
  exception ever reaching `JobAttempted`.
- `JobTimedOut` is the one path that never reaches `JobAttempted`, because
  the worker's SIGALRM handler kills the process immediately after
  dispatching it; the recorder treats it as its own terminal outcome.

Listening to the granular events directly was rejected: `JobProcessed` and
`JobReleased` both fire for a job that calls `$this->release()` from inside
`handle()` without throwing, which would double count that attempt, and a job
that calls `$this->fail()` manually still gets a `JobProcessed` dispatched
right after `fire()` returns, which would undercount failures. Deriving the
outcome from the job object's own state after `JobAttempted` avoids both
failure modes with fewer listeners.

Wait time is computed once, at `JobProcessing`, as the time since the job's
`createdAt` payload timestamp (the same timestamp Laravel stamps at push
time). Runtime is the elapsed time between the in-memory `JobProcessing`
timer and the terminal event. Both are best-effort: if `JobProcessing` was
never observed for a given attempt (for example, the recorder was enabled
after the job was already reserved), the outcome is still recorded, without a
runtime or wait sample.

The node identity recorded for every event defaults to the local hostname,
overridable with `zenith.telemetry.node`. The supervisor name is read
directly from the worker process's own command line rather than through a
dedicated listener: `horizon:work` always receives `--supervisor=<name>`
(`Laravel\Horizon\QueueCommandString`), so `WorkerIdentity` parses it out of
`$_SERVER['argv']`.

### Storage shape

Every recorded attempt increments Redis hash fields across three fixed
retention tiers (`TelemetryResolution`), so a dashboard can plot both a
noisy, second-by-second live view and a smoothed multi-day trend from the
same recorder:

| Tier       | Bucket width | Retention | Key                                            |
|------------|--------------|-----------|-------------------------------------------------|
| `fine`     | 1 second     | 15 minutes| `zenith:v1:telemetry:bucket:fine:<bucketStart>`     |
| `standard` | 1 minute     | 24 hours  | `zenith:v1:telemetry:bucket:standard:<bucketStart>` |
| `coarse`   | 5 minutes    | 7 days    | `zenith:v1:telemetry:bucket:coarse:<bucketStart>`   |

(Keys are actually prefixed with the ASCII unit separator, matching
`BulkOperationSnapshot`'s convention; it is omitted above for readability.)
Each bucket key is a single Redis hash holding every dimension's counters and
histograms for that time slice, rather than one key per dimension value, so
the number of live keys is bounded by the number of open buckets
(at most 900 + 1440 + 2016 = 4356) regardless of how many queues, job
classes, or nodes are active. Both the bucket key and a companion sorted-set
index of open bucket timestamps (used to discover which buckets exist without
an Redis `SCAN`) carry the tier's TTL, renewed on every write; the index
prunes its own expired entries on each write via `ZREMRANGEBYSCORE` before
adding the new bucket.

Within a bucket hash, fields are named with the same unit-separator
convention:

- `count\x1f<dimension>\x1f<value>\x1f<outcome>` — an attempt counter, where
  `<dimension>` is `queue`, `class`, `node`, or `connection`, and `<outcome>`
  is `processed`, `failed`, `released`, or `timed_out`.
- `hist\x1f<metric>\x1f<dimension>\x1f<value>\x1f<bucketIndex>` — a duration
  histogram sample, where `<metric>` is `runtime` or `wait` and
  `<bucketIndex>` is a log2-scaled bucket from `DurationHistogram` (1ms,
  2ms, 4ms, ... up to roughly 4.6 hours before durations clamp into the final
  bucket).

Every attempt increments exactly one counter field and up to two histogram
fields per dimension per tier: a fixed 4 to 12 `HINCRBY` calls per tier
depending on whether timings are available, times three tiers. This bound is
asserted directly in `TelemetryRecorderTest` (a deterministic proof of
overhead, since a network-bound micro-benchmark would be flaky in CI) rather
than measured with a wall clock. There is no dimension value for "all
queues" or "all classes": since every attempt has exactly one queue, class,
node, and connection, a global total for any dimension is the sum of that
dimension's own values, not a separately stored series. Percentiles for a
given window are
derived by summing histogram bucket counts across every open bucket in that
window and walking the cumulative distribution until it crosses the target
percentile, then reporting that bucket's upper bound.

### Fallback

Every telemetry-backed screen checks `horizon.telemetryEnabled` (shared by
`HandleInertiaRequests`, sourced from `TelemetryRegistration::enabled()`)
before rendering recorder-fed charts or tables, and falls back to the
existing Horizon-snapshot behavior otherwise: metrics charts fall back to
`MetricsRepository` snapshots, and screens with no snapshot equivalent (live
percentiles, in-flight jobs, attempt history) show an explanatory empty state
instead of an error. `TelemetryRegistration::register()` returns before
touching the event dispatcher at all when disabled, so a disabled recorder
adds zero listeners and zero Redis calls to job processing — not merely a
no-op recorder, but one that is never registered.

## Queue bypass visibility

Horizon, and therefore Zenith, only see jobs that reach a queue connection Horizon actually supervises. Laravel 13's `failover` driver can silently move a job onto its `database` or `sync` fallback connection when the primary is unreachable, and the `deferred` and `background` connections are designed to run outside Horizon entirely. Jobs that take any of these paths are invisible to both: Horizon never reserves them, so they never appear in retained pending, completed, or failed history. `QueueFailoverActivity` narrows this blind spot to visibility, not coverage: it listens for `Illuminate\Queue\Events\QueueFailedOver` and keeps a short, cache-backed window of recent occurrences (`zenith.queue_failover`), and `QueueBypassWarning` cross-references `config('queue.connections')` for connections configured with a bypass-prone driver. Both feed a dashboard and queues-page banner that tells an operator to look outside Zenith, but the package cannot recover or display the jobs themselves.

This blind spot is narrower than it looks, though: `deferred` and `background` both extend `Illuminate\Queue\SyncQueue`, which dispatches `JobProcessing`/`JobAttempted`/`JobExceptionOccurred` directly from `executeJob()`, independent of any Horizon worker. The telemetry recorder (above) listens to exactly those events, so a job executed through either connection is still recorded — grouped by its `connection` dimension — as long as `zenith.telemetry.enabled` is on. Horizon's supervisor pages stay blind to these jobs (there is still no queue entry for them to sit in), but Live Metrics is not: filtering or grouping by "Connection" surfaces exactly what ran through a bypass-prone connection and how it performed.

Laravel 13's own default `config/queue.php` ships `deferred` and `failover` connections out of the box, so the banner fires for most Laravel 13 apps even when neither is ever dispatched to. `zenith.queue_failover.ignored_connections` lists connection names `QueueBypassWarning` should treat as a deliberate, acknowledged choice rather than something to flag — it drops them from both `bypassProneConnections` and the recent-failover counters. It defaults to empty, so an unused stub connection still surfaces until an operator explicitly acknowledges it.

## Frontend isolation

`HandleInertiaRequests` sets `zenith::app` as the root view only for package routes. This avoids changing the host application's own Inertia middleware or root template.

The service provider excludes the configured Horizon path and its descendants
from the host Inertia SSR gateway. Zenith has no SSR entry point and never
asks a consuming application's SSR bundle to resolve package pages. When the
host enables Laravel's Vite CSP nonce, the root view applies it to package
bootstrap and Vite-rendered tags and shares it with the Inertia client for
runtime-injected styles.

The React entry point resolves package pages from `resources/js/pages`. Every page opts into the same Inertia persistent layout, so sidebar and automatic-loading state survive client-side navigation. Theme preference is stored independently and supports light, dark, and system modes.

UI is composed from shadcn/ui primitives. Package-specific components are limited to Horizon data presentation where shadcn/ui has no domain equivalent, such as metrics charts, sortable Horizon tables, JSON payload views, and compact circular batch progress.

Wayfinder generates typed definitions from package routes. `resolveHorizonRoute` rebases those definitions against the shared Horizon base URL while preserving route methods, parameters, query strings, and hashes. This keeps all links and mutations correct for relative custom paths and absolute Horizon domain URLs. Generated files under `resources/js/generated` are never edited manually.

Paginated filters and database-batch sorts issue Inertia requests, reset the
continuation cursor, and operate on the complete queryable source before the
next 50-row page is returned. Job-column sorting is deliberately different: it
reorders all rows currently loaded in the browser and re-sorts after each
appended page. Unsupported global controls are omitted. Monitored-tag lists keep
Horizon's repository order. Batch detail job tables may sort locally only when
their non-paginated retained list is marked complete; incomplete lists keep
repository order and plain headers.

Jobs search and exact filters are URL-backed and reset their continuation
cursor together. The filter affordance remains in the toolbar while its
optional catalog resolves. Hovering, focusing, or opening the filter triggers a
catalog-only partial reload; normal list polling never requests it. When the
catalog is unavailable it shows the failure reason and stays disabled unless
active filters must remain clearable, avoiding a shifting or disappearing
control.

Optional automatic loading is coordinated by the persistent layout. A
background refresh replaces the authoritative first page so expired, deleted,
or reordered records cannot remain in the interface through an additive merge.
After infinite scrolling starts loading older history, Zenith follows
Horizon's page-one refresh model: it stops polling the scroll prop, continues
polling only the list revision and summary props, and shows the “new entries”
action when the source changes. That action resets the collection to the
current first page and resumes automatic first-page refresh. Infinite-scroll
requests begin before the viewport reaches the end, and Inertia owns the
rendered collection, boundary observer, reset, and loading lifecycle without
package-rendered loading or row-reconciliation fallbacks. PHP remains the only
layer that reads Horizon repositories.

## Asset delivery

The package ships a committed Vite production build in `dist/build`. Consumers
publish it with:

```bash
php artisan zenith:install
php artisan zenith:assets
```

`zenith:install` publishes configuration and compiled assets, runs
install-only production prerequisite warnings, and appends
`@php artisan zenith:assets --ansi` to the host root
`composer.json` `scripts.post-autoload-dump` (unless `--no-composer-hook` is
passed). Composer executes only root-package scripts, so later
`composer install` / `composer update` runs refresh assets through that hook.
`zenith:assets` publishes only the fixed
`public/vendor/zenith/build` tree and performs no config publish or
prerequisite checks.

Both commands share `AssetsPublisher`. A normal run treats
`public/vendor/zenith/build` as a package-owned tree: it compares the
package build and published directory as an exact file set, refreshes when the
publication is missing, partial, stale, forced, or contains files absent from
the current package build, and no-ops only when the trees match exactly. On
refresh it stages a validated copy of `dist/build`, then replaces the whole
destination via Laravel Filesystem directory moves with rollback if replacing
an existing destination fails. That swap is not a guaranteed gap-free exchange
on every platform; the destination path can be briefly absent between moving
the previous tree aside and moving the staged tree into place. Prior hashed
assets and consumer-added files inside the package-owned directory are removed
on refresh, not preserved.
`AssetManifest` renders entry tags through a package-scoped
`Illuminate\Foundation\Vite` instance pointed at
`public/vendor/zenith/build` and Laravel's root `manifest.json`
convention. That instance uses a package-only hot-file path so a consuming
application's `public/hot` never hijacks package assets, and it never mutates
the host Vite singleton. It still reuses the host CSP nonce from
`Vite::useCspNonce()` for generated tags. Inertia asset versions come from
`manifestHash()`. A consuming application does not need Node.js or changes to
its own Vite configuration. Deployments that disable Composer scripts or ship
an immutable `public/vendor/zenith/build` artifact can skip the
Artisan publish step and call `zenith:assets` only when needed.

## Job history

### Context

Horizon trims its own retained job history globally, on a single schedule
measured in minutes or hours, using Horizon's `trim` configuration. Some
operators want a longer-lived, queryable record of terminal job outcomes for
audits, dashboards, or debugging past that window, similar to how Oban keeps
completed and discarded jobs in Postgres with configurable pruning rules.
Zenith did not previously persist any job outcome beyond what Horizon retains
in Redis, and Horizon's own retention carries no granularity below its single
global `trim` window.

### Decision

An opt-in `zenith_job_history` migration adds a package table storing one row
per terminal job attempt: `job_class`, `queue`, `connection`, `status`
(`completed`, `silenced`, or `failed`), `attempts`, `runtime_ms`, `tags` as
JSON, a truncated `error` summary, and `pushed_at`, `completed_at`, and
`failed_at` timestamps. Consistent with the package's existing rule against
exposing raw payloads or exception internals, no job payload and no exception
trace are ever stored; the `error` column is a short, truncated `class:
message` summary only, and payload access is limited to reading known JSON
keys (`tags`, `createdAt`, `silenced`) rather than unserializing the job.

`ZenithServiceProvider` registers a `JobHistoryRecorder` against Laravel's own
`Illuminate\Queue\Events\JobProcessing`, `JobProcessed`, and `JobFailed`
events rather than Horizon-specific events, so history recording behaves
identically across every queue connection Horizon supervises, and does not
depend on the separate event-driven telemetry recorder built for live,
short-term metrics. Each handler checks `zenith.history.enabled` first; when
the flag is `false` (the default), it returns immediately without touching
the stopwatch, the schema, or the database, so the feature costs nothing when
it is off. When enabled, a package-owned `JobHistoryStopwatch` singleton times
each attempt independently of Horizon's own `Stopwatch`, so recording never
depends on the relative order Horizon's internal listeners run in for a
different event chain.

Retention rules are configuration-first and stored under
`zenith.history.retention` as an ordered list of rule arrays, each with an
optional `queue`, `class`, and/or `status` filter and a required `days` value.
`zenith:prune-history` evaluates rules top to bottom: the first rule (in
config order) whose filters match a row governs that row's retention, the
same way an ordered firewall or routing table works. Each rule's delete query
excludes rows already claimed by every earlier rule, so a specific rule
listed before a broad or default rule is never overridden by it. A rule with
no filters at all is a catch-all; once one runs, no later rule can match
anything, so operators should list catch-all rules last. Rows that no
configured rule matches are kept indefinitely rather than guessed at, which is
the safer default for a feature whose entire purpose is durable history. The
command is a plain Artisan command meant to be scheduled with
`withoutOverlapping()` from the host application, matching how
`horizon:snapshot` is documented in the README, rather than a package-owned
internal scheduled event; a runtime-editable retention UI is intentionally
out of scope here and tracked separately.

A dedicated `zenith_job_history_archive` table was considered so pruning could
move rows out instead of deleting them, mirroring Oban's optional archive.
This iteration decides against it: `zenith_job_history` is already the
durable, queryable record the feature exists to provide, and duplicating it
into a second archive tier before any consumer needs one would be speculative
complexity. If audit-grade retention of pruned rows becomes a real
requirement, it is a natural follow-up rather than a reason to delay this
feature.

A minimal read view on the Jobs pages is also out of scope for this
iteration; the storage, retention, and pruning primitives are the
deliverable.

### Consequences

Recording is best-effort: a `JobHistoryRecorder` write failure is reported and
swallowed rather than allowed to fail the job or the worker, matching
`HorizonAuditRecorder`'s existing pattern. `pushed_at` is populated only when
the job's own payload carries Laravel's `createdAt` timestamp, which is not
guaranteed for every possible job source; when absent, `pushed_at` is `null`
rather than an inferred value. Runtime is measured per attempt, not
cumulatively across retries, so a job that failed twice before succeeding
reports the successful attempt's own runtime, not the total wall time across
every attempt. Operators who enable history recording without configuring any
retention rules will grow `zenith_job_history` unbounded until they add rules
and schedule the prune command; this is intentional so enabling history never
silently deletes data before an operator has decided a retention policy.

## Search design: tags, arguments, and qualifiers

Every retained job type (pending, completed, silenced, and failed) now
maintains the same `tag` facet that failed jobs already exposed. Tags were
already captured once per job in `RetainedJobMetadata` and were already a
peer of `job`, `queue`, and `connection` in `RetainedJobIndex`'s generic facet
maintenance; only the type-scoped filter that skipped indexing tags for
pending and silenced jobs needed to go. Reconciliation, rebuild, and
structural corruption recovery already loop over every facet dimension
uniformly, so enabling the dimension for every type added no new code path,
only more Redis membership per retained job (one sorted-set entry per tag the
job carries, same shape and cost as the existing job-class facet). `tag` is
intentionally excluded from `SINGLE_VALUED_CATALOG_DIMENSIONS`: a job can
carry many tags, so its facet membership cannot be proven an exact disjoint
partition the way single-valued dimensions are, and it is exposed only as an
exact-match filter (`JobIndexFiltersData::$tag`), never as a discovered
options catalog. A minimal `tag:` qualifier in the free-text search box
(`JobSearchQualifiers`) extracts one leading `tag:value` token and merges it
into that same facet filter; everything else in the search string still falls
through to the existing partial job-class or exact-ID search untouched. This
part of the issue was fully implementable with the existing projection and
shipped in this pass.

Opt-in per-argument search and a general qualifier parser (`queue:emails
tag:vip args.order_id:42`) are a different order of problem and were
deliberately left as a documented decision rather than a partial
implementation:

- **Cost model.** `job`, `queue`, `connection`, and now `tag` are all
  low-cardinality, host-controlled facets: a Horizon installation has a
  bounded number of job classes, queues, connections, and, in practice, tags
  drawn from a small vocabulary an application chooses (`tenant:*`,
  `user:*`). Indexing them costs one Redis sorted-set membership per job per
  dimension, bounded by that installation's actual cardinality. Job
  *arguments* have none of these properties: an argument value can be
  arbitrary-cardinality (an order ID, a UUID, a free-text field), arbitrary
  shape (scalar, array, nested object), and mutable per job class. Projecting
  even one argument key per job class multiplies retained-history storage by
  the number of distinct values ever seen, with no natural upper bound, and
  every additional opted-in key repeats that cost. The existing partial
  full-text class search already accepts this trade-off for `job`, `queue`,
  and `connection` catalogs specifically because those are the union of a
  small, closed set of Redis keys, not a per-record index.
- **What we would index if we built it.** Per job class, a host would declare
  a fixed list of argument keys to project (mirroring how `job_navigation_breakdown`
  and `redact_payload_keys` are already host-configured, closed lists). Each
  declared key would get its own facet dimension, scoped to that job class,
  populated the same way `tag` is today: read once from the decoded payload
  during reconciliation, written into a `facetKey($type, "arg:{class}:{key}", $value)`
  sorted set. That is additive to the current design and would not require
  touching the generic facet-maintenance loop again.
- **Why not now.** The qualifier syntax (`args.order_id:42`) implies parsing
  and validating an open-ended right-hand side per declared key, choosing
  between exact and substring matching per value type, and deciding how an
  argument search composes with the existing partial-class search term in one
  query box — none of which has a natural, bounded answer without first
  shipping the opt-in projection and seeing which key types and match modes
  real job classes actually need. Building the parser before that would mean
  guessing at a contract this package would then have to keep. The `tag`
  facet above proves the mechanism scales to a second dimension cleanly;
  opt-in argument projection is the next candidate for the same mechanism, but
  belongs in its own change once a job class's declared keys and match
  semantics are decided with real usage in hand, not speculatively in this
  pass.

## Extension boundaries

Use Horizon contracts when a feature already exists in Horizon. Add a focused package service or action when Zenith needs normalization, repository-backed aggregation, or a mutation Horizon does not expose through a suitable contract.

The capabilities that should eventually move upstream, together with the
package code each one would replace, are tracked in
[`docs/upstream-opportunities.md`](upstream-opportunities.md).

New routes must:

- remain under the configured Horizon path unless the feature has a clear reason to live elsewhere;
- use Horizon's authorization boundary;
- return structured Laravel Data objects to Inertia;
- avoid exposing raw job payloads, exception bodies, or Redis details;
- avoid modifying or shadowing Horizon API routes unless the change is intentional and documented.

## Anti-starvation alerting

`QueueStarvationAlert` builds on `QueueWaitThreshold`'s already-computed
`oldestReadyAgeSeconds` to flag a queue whose oldest ready job has aged past
`zenith.starvation.threshold_seconds`. It is deliberately alert-only: it
never reprioritizes, moves, or otherwise touches a job. A starved queue is
just data surfaced on `QueueRowData`, for an operator to act on.

Actually promoting a starved job (moving it, or a redispatched copy of it,
onto a higher-priority queue so it is picked up sooner) was considered and
rejected for this pass:

- Horizon's queue priority comes entirely from worker configuration (the
  order queues are listed for a supervisor), not from any per-job priority
  field. Promoting a job means either moving its payload to a different
  Redis list Horizon does not know originated elsewhere, or re-dispatching a
  copy and cancelling the original — both change a job's queue, connection,
  and retry/attempt history under the operator's feet, which this package
  has otherwise been careful never to do implicitly (see the bulk-operations
  and pending-job-cancellation sections above).
- Promotion needs a policy for *how much* to promote by and *how often* to
  re-evaluate, or it just turns into a second, competing scheduler sitting
  on top of Horizon's own supervisors. Getting that wrong (over-promoting)
  can starve the queues a job was promoted away from, trading one starvation
  problem for another.
- A promoted job that fails still needs its failure attributed to its
  original queue for metrics and alerting to keep making sense, which the
  alert-only design gets for free by never moving anything.
- Alerting has an immediate, low-risk payoff: an operator (or a scheduled
  command reading this same data) can rebalance supervisor queue lists, add
  workers, or manually requeue a specific job, all decisions that need
  human or application-specific judgment Horizon's generic queue mechanics
  cannot make safely on their own.

Promotion remains worth revisiting as an explicit, opt-in feature once
alerting has been observed in practice — for example, a supervisor-scoped
option that only ever *copies* a starved job onto an explicitly configured
overflow queue after it has been starved for a second, longer threshold,
leaving the original job and its history untouched until the copy
succeeds.

## Design decisions

Some capabilities on the [Oban parity roadmap](https://github.com/devaction-labs/zenith/issues/44) were opened as RFCs rather than issues with a settled design. This section records the decision each one reached.

### Fetch-time gating for limits (issue #38): no-go for now

Oban applies concurrency and rate limits when a worker fetches a job, so a saturated limit or partition never leaves the queue. Horizon's fetch path is a Lua script internal to the package (`RedisQueue`'s pop scripts), not a published extension point: reproducing fetch-time gating means forking that script and re-patching it on every Horizon release, with no upstream contract protecting the fork from silently breaking. `EnforceQueueBudget` and `EnforceQueueConcurrency` (issue #31) apply limits after the pop instead, which costs a release-and-redeliver cycle per throttled job but stays entirely within Horizon's supported surface.

Decision: stay with after-pop gating. Revisit only if Horizon publishes a documented fetch-time extension point, or if release churn measured through the telemetry recorder (issue #14) proves costly enough for a specific workload to justify maintaining a fork.

### Autoscaling signals for external schedulers (issue #39): go, via a CLI export, not a new HTTP endpoint

Oban Pro's Dynamic Scaler scales worker nodes horizontally from queue depth, wait time, and throughput. Horizon's own auto-balancing only scales processes within a node. Exposing those numbers so KEDA or the Kubernetes HPA can scale nodes is worth doing, but Zenith's design goals rule out adding a second general browser-facing API surface, and a scraped HTTP endpoint would be exactly that: a new, generally reachable route outside Horizon's existing read/write contract.

Decision: `php artisan zenith:export-metrics` prints Prometheus text-format samples for queue depth, wait time, and throughput to stdout, reusing the same `QueuesData`/`MetricsRepository` sources the dashboard already reads. It opens no route. Recipes:

- **Textfile collector**: a cron writes the command's output to `node_exporter`'s textfile collector directory; Prometheus scrapes it from there.
- **Pushgateway**: pipe the output through `curl --data-binary @- http://pushgateway:9091/metrics/job/zenith` on a schedule.
- **KEDA / HPA**: point a `ScaledObject` or the Prometheus custom-metrics adapter at whichever of the above populates Prometheus; Zenith itself stays unaware of the autoscaler.

### AI-assisted failure explanations (issue #40): go, as a hook with no new dependency

Explaining a failure well needs an LLM call, but Zenith has no opinion on which provider a host application uses, and adding one as a package dependency would force that choice (and its cost) on every consumer, including those who never touch the feature.

Decision: `Zenith::explainFailureUsing(callable $callback)` lets the host register `fn (string $jobClass, string $exceptionMessage): ?string` from its own service provider, backed by whatever AI client it already depends on. Zenith never talks to a provider itself. The failed-job detail page shows an "Explain this failure" action only when a callback is registered, gated by the existing `retryJobs` ability, so hosts that skip the hook see no change.

### Transactional outbox for job dispatch (issue #35): go, single delivery path via sweep

Oban's own outbox gives an at-least-once dispatch guarantee: a job enqueued inside the same transaction as the application data it depends on either commits with that data or rolls back with it, closing the gap where a process crashes after committing but before the job reaches the real queue. Horizon has no equivalent — `dispatch()` inside a transaction only pushes once the transaction commits (via `afterCommit`), which still loses the job if the process dies between that commit and the push.

A design with two paths — an immediate push after commit, falling back to a sweep for whatever the fast path missed — was rejected: it means the job can arrive by two different routes, doubles the surface a test has to cover, and the race between "did the fast path already send it" and "is the sweep about to send it too" is exactly the kind of bug this feature exists to prevent.

Decision: `Outbox::dispatch($job, $connection, $queue)` only ever writes a row inside the caller's transaction; delivery happens exclusively through `Outbox::relayDue($graceSeconds)`, scheduled every minute as `zenith:relay-outbox`. A grace period (default 30s) avoids relaying a row whose owning transaction is still open on another connection. This is at-least-once, not exactly-once: a crash between a successful push and deleting the row redelivers it, so jobs dispatched through the outbox should stay idempotent or use `ShouldBeUnique`, the same expectation Oban sets. `Outbox::backlog()` reports the pending row count and the oldest row's age, exported as `zenith_outbox_backlog` and `zenith_outbox_oldest_pending_seconds` alongside the other autoscaling gauges (issue #39) so a stuck sweep is observable the same way a growing queue is.

## Development environment

Orchestra Testbench verifies package behavior in isolation. The Workbench application provides deterministic successful and failing jobs for live dashboard development.

The primary verification boundaries are:

- feature tests for route ownership, middleware isolation, authorization, Inertia props, actions, and asset publishing;
- unit tests for Horizon repository normalization, retained-source cursors, search escaping, and failure handling;
- React tests for route rebasing, query controls, components, persistent layout, themes, and automatic loading;
- browser acceptance proving exact filters can select records beyond the first 50 retained rows and loaded-row sorting remains stable as pages append;
- a production Vite build whose manifest references only committed files;
- responsive light/dark browser validation through an Orbit-managed Laravel application.
