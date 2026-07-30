import { shouldIntercept, type VisitOptions } from "@inertiajs/core";
import { config, InfiniteScroll, Link, router } from "@inertiajs/react";
import { HistoryIcon } from "lucide-react";
import { useEffect, useRef } from "react";

import { BatchTable } from "@/components/batches/batch-table";
import { QueueBatchesActions } from "@/components/batches/queue-batches-actions";
import { FailedJobTable } from "@/components/jobs/failed-job-table";
import { JobTable } from "@/components/jobs/job-table";
import { PendingJobsActions } from "@/components/jobs/pending-jobs-actions";
import { QueueActionsMenu } from "@/components/queues/queue-actions-menu";
import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent } from "@/components/ui/card";
import { Tabs } from "@/components/ui/tabs";
import { show as queueShow } from "@/generated/routes/horizon-new-dawn/queues";
import { useSortableRows, type SortColumn } from "@/hooks/use-sortable-rows";
import { formatCount } from "@/lib/format-count";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { BatchRow } from "@/types/batches";
import type { JobRow } from "@/types/jobs";
import type {
  QueueActivity,
  QueueActivityTab,
  QueueDetailView,
  QueueSummary,
} from "@/types/queues";

const tabs: Array<{ value: QueueActivityTab; label: string }> = [
  { value: "pending", label: "Pending Jobs" },
  { value: "completed", label: "Completed Jobs" },
  { value: "failed", label: "Failed Jobs" },
  { value: "silenced", label: "Silenced Jobs" },
  { value: "batches", label: "Batches" },
];
const sortColumns = {
  pending: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
  ],
  completed: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
    { key: "completedAt", value: (job) => job.completedAt },
    { key: "runtime", value: (job) => job.runtime },
  ],
  failed: [
    { key: "name", value: (job) => job.name },
    { key: "runtime", value: (job) => job.runtime },
    { key: "failedAt", value: (job) => job.failedAt },
  ],
  silenced: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
    { key: "completedAt", value: (job) => job.completedAt },
    { key: "runtime", value: (job) => job.runtime },
  ],
} satisfies Record<Exclude<QueueActivityTab, "batches">, readonly SortColumn<JobRow>[]>;
const completedAtDescending = {
  key: "completedAt",
  direction: "desc",
} as const;
const batchSortColumns = [
  {
    key: "progress",
    value: (batch: BatchRow) =>
      batch.status !== "cancelled" && batch.pendingJobs > 0 ? 101 + batch.progress : 0,
  },
] satisfies readonly SortColumn<BatchRow>[];
const progressDescending = {
  key: "progress",
  direction: "desc",
} as const;

function tabCount(summary: QueueSummary, tab: QueueActivityTab): string {
  const [value, complete] = (
    {
      pending: [summary.pendingJobs, summary.pendingComplete],
      completed: [summary.completedJobs, summary.completedComplete],
      failed: [summary.failedJobs, summary.failedComplete],
      silenced: [summary.silencedJobs, summary.silencedComplete],
      batches: [summary.batches, summary.batchesComplete],
    } satisfies Record<QueueActivityTab, readonly [number | null, boolean]>
  )[tab];

  if (value === null) {
    return "—";
  }

  const count = formatCount(value);

  return complete ? count : `${count}+`;
}

export function QueueActivityTabs({
  queue,
  tab,
  view,
  summary,
  activity,
  horizonBaseUrl,
  batchAttributionAvailable = false,
  querySignature,
  hasNewEntries,
  onLoadNewEntries,
  onBeforeNextPage,
  inactive = false,
}: {
  queue: string;
  tab: QueueActivityTab;
  view: QueueDetailView;
  summary: QueueSummary;
  activity: QueueActivity;
  horizonBaseUrl: string;
  batchAttributionAvailable?: boolean;
  querySignature: string;
  hasNewEntries: boolean;
  onLoadNewEntries: () => void;
  onBeforeNextPage: () => void;
  inactive?: boolean;
}) {
  const hoverPrefetchTimeout = useRef<number | undefined>(undefined);
  const batchRows = tab === "batches" ? (activity.data as readonly BatchRow[]) : [];
  const batchFailedJobs =
    tab === "batches" ? batchRows.reduce((total, batch) => total + batch.failedJobs, 0) : 0;
  const availableTabs = tabs
    .filter((item) => item.value !== "batches" || batchAttributionAvailable)
    .map((item) => {
      const route = queueShow(encodeURIComponent(queue), {
        query: view === "metrics" ? { tab: item.value, view } : { tab: item.value },
      });
      const href = resolveHorizonRoute(route, horizonBaseUrl).url;
      const visitOptions = {
        replace: true,
        preserveScroll: true,
        preserveState: true,
        only: [
          "activity",
          "tab",
          "view",
          "querySignature",
          "listRevision",
          "horizon",
          "navigationCounts",
        ],
        reset: ["activity"],
      } satisfies VisitOptions;

      return {
        ...item,
        count: tabCount(summary, item.value),
        href,
        visitOptions,
      };
    });

  useEffect(() => {
    window.clearTimeout(hoverPrefetchTimeout.current);

    return () => {
      window.clearTimeout(hoverPrefetchTimeout.current);
    };
  }, [querySignature, queue, tab]);

  const cancelHoverPrefetch = () => {
    window.clearTimeout(hoverPrefetchTimeout.current);
  };

  const prefetchAfterHoverDelay = (href: string, visitOptions: VisitOptions) => {
    cancelHoverPrefetch();
    hoverPrefetchTimeout.current = window.setTimeout(
      () => router.prefetch(href, visitOptions),
      config.get("prefetch.hoverDelay"),
    );
  };
  const selectTab = (value: QueueActivityTab | null) => {
    const nextTab = availableTabs.find((item) => item.value === value);

    if (!nextTab || nextTab.value === tab) {
      return;
    }

    router.visit(nextTab.href, nextTab.visitOptions);
  };
  const activityActions = inactive ? null : tab === "batches" ? (
    <QueueBatchesActions
      queue={queue}
      failedJobs={batchFailedJobs}
      horizonBaseUrl={horizonBaseUrl}
    />
  ) : tab === "pending" ? (
    <PendingJobsActions
      horizonBaseUrl={horizonBaseUrl}
      queue={queue}
      counts={{
        ready: summary.pendingReadyNow,
        delayed: summary.pendingDelayed,
      }}
      disabled={!activity.available}
    />
  ) : tab === "failed" ? (
    <QueueActionsMenu
      queue={queue}
      targets={summary.pauseTargets}
      failedJobs={summary.failedJobs}
      horizonBaseUrl={horizonBaseUrl}
      scope={tab}
    />
  ) : null;

  return (
    <Card id="queue-activity" tabIndex={-1} className="scroll-mt-3.5 outline-none">
      <CardContent className="p-0">
        <Tabs
          value={tab}
          className="gap-0 [&_[data-slot=table-cell]:first-child]:pl-4 [&_[data-slot=table-cell]:last-child]:pr-4 [&_[data-slot=table-cell]:last-child:has(button)]:pr-2.5 [&_[data-slot=table-head]:first-child]:pl-4 [&_[data-slot=table-head]:last-child]:pr-4 sm:[&_[data-slot=table-cell]:first-child]:pl-6 sm:[&_[data-slot=table-cell]:last-child]:pr-6 sm:[&_[data-slot=table-cell]:last-child:has(button)]:pr-6 sm:[&_[data-slot=table-head]:first-child]:pl-6 sm:[&_[data-slot=table-head]:last-child]:pr-6"
        >
          <ResponsiveTabsHeader
            value={tab}
            items={availableTabs.map((item) => ({
              value: item.value,
              label: item.label,
              count: item.count,
              render: (
                <Link
                  href={item.href}
                  prefetch={false}
                  aria-label={`${item.label} ${item.count}`}
                  onFocus={() => router.prefetch(item.href, item.visitOptions)}
                  onMouseEnter={() => prefetchAfterHoverDelay(item.href, item.visitOptions)}
                  onMouseLeave={cancelHoverPrefetch}
                  onClick={(event) => {
                    if (event.currentTarget.hasAttribute("download") || !shouldIntercept(event)) {
                      return;
                    }

                    event.preventDefault();
                    cancelHoverPrefetch();
                    router.visit(item.href, item.visitOptions);
                  }}
                />
              ),
            }))}
            ariaLabel="Queue activity"
            onValueChange={selectTab}
            actions={activityActions}
            className="border-b border-separator sm:pr-6"
          />

          <QueueActivityContent
            key={`${queue}:${tab}:${querySignature}`}
            queue={queue}
            tab={tab}
            activity={activity}
            horizonBaseUrl={horizonBaseUrl}
            querySignature={querySignature}
            hasNewEntries={hasNewEntries}
            onLoadNewEntries={onLoadNewEntries}
            onBeforeNextPage={onBeforeNextPage}
            inactive={inactive}
          />
        </Tabs>
      </CardContent>
    </Card>
  );
}

function QueueActivityContent({
  queue,
  tab,
  activity,
  horizonBaseUrl,
  querySignature,
  hasNewEntries,
  onLoadNewEntries,
  onBeforeNextPage,
  inactive,
}: {
  queue: string;
  tab: QueueActivityTab;
  activity: QueueActivity;
  horizonBaseUrl: string;
  querySignature: string;
  hasNewEntries: boolean;
  onLoadNewEntries: () => void;
  onBeforeNextPage: () => void;
  inactive: boolean;
}) {
  const activityItemsRef = useRef<HTMLTableSectionElement>(null);
  const jobRows = tab === "batches" ? [] : (activity.data as readonly JobRow[]);
  const batchRows = tab === "batches" ? (activity.data as readonly BatchRow[]) : [];
  const displayAvailable = activity.available || activity.warming;
  const displayMessage = activity.warming ? null : activity.message;
  const emptyTitle = activity.warming
    ? tab === "batches"
      ? "Preparing attributed batches"
      : `Preparing ${tab} jobs`
    : tab === "batches"
      ? "No attributed batches"
      : `No retained ${tab} jobs`;
  const emptyDescription = inactive
    ? "Run php artisan horizon to start an instance and process queues."
    : activity.warming
      ? "Horizon is updating retained history. Results will appear automatically."
      : activity.complete
        ? tab === "batches"
          ? "No retained batches are attributed to this queue."
          : `Horizon is not retaining any ${tab} jobs for this queue.`
        : "No matches in the inspected history. More retained entries may exist.";
  const columns = tab === "batches" ? [] : sortColumns[tab];
  const sortedJobs = useSortableRows(jobRows, columns, {
    persist: tab !== "batches",
    defaultSort: tab === "completed" || tab === "silenced" ? completedAtDescending : undefined,
  });
  const sortedBatches = useSortableRows(batchRows, batchSortColumns, {
    defaultSort: progressDescending,
  });

  return (
    <>
      {activity.available && !activity.warming && activity.message ? (
        <Alert className="m-4">
          <HistoryIcon aria-hidden="true" />
          <AlertTitle>
            {tab === "batches" ? "Batch activity limitation" : "Retained history is incomplete"}
          </AlertTitle>
          <AlertDescription>{activity.message}</AlertDescription>
        </Alert>
      ) : null}

      <InfiniteScroll
        key={`${queue}:${tab}:${querySignature}:${activity.available}`}
        data="activity"
        itemsElement={activityItemsRef}
        onlyNext
        preserveUrl
        buffer={600}
        params={{ onBefore: onBeforeNextPage }}
      >
        {tab === "batches" ? (
          <BatchTable
            batches={sortedBatches.rows}
            horizonBaseUrl={horizonBaseUrl}
            available={displayAvailable}
            message={displayMessage}
            emptyTitle={inactive ? "No Horizon instances" : emptyTitle}
            emptyDescription={emptyDescription}
            showBatchActions
            hasNewEntries={hasNewEntries}
            onLoadNewEntries={onLoadNewEntries}
            bodyRef={activityItemsRef}
            sorting={{
              key: sortedBatches.sort?.key ?? null,
              direction: sortedBatches.sort?.direction ?? "asc",
              columns: batchSortColumns.map((column) => column.key),
              onSort: sortedBatches.toggle,
            }}
          />
        ) : tab === "failed" ? (
          <FailedJobTable
            jobs={sortedJobs.rows}
            horizonBaseUrl={horizonBaseUrl}
            available={displayAvailable}
            message={displayMessage}
            emptyTitle={inactive ? "No Horizon instances" : emptyTitle}
            emptyDescription={emptyDescription}
            hasNewEntries={hasNewEntries}
            onLoadNewEntries={onLoadNewEntries}
            sorting={{
              key: sortedJobs.sort?.key ?? null,
              direction: sortedJobs.sort?.direction ?? "asc",
              columns: columns.map((column) => column.key),
              onSort: sortedJobs.toggle,
            }}
            bodyRef={activityItemsRef}
          />
        ) : (
          <JobTable
            jobs={sortedJobs.rows}
            type={tab}
            horizonBaseUrl={horizonBaseUrl}
            available={displayAvailable}
            message={displayMessage}
            emptyTitle={inactive ? "No Horizon instances" : emptyTitle}
            emptyDescription={emptyDescription}
            hasNewEntries={hasNewEntries}
            onLoadNewEntries={onLoadNewEntries}
            sorting={{
              key: sortedJobs.sort?.key ?? null,
              direction: sortedJobs.sort?.direction ?? "asc",
              columns: columns.map((column) => column.key),
              onSort: sortedJobs.toggle,
            }}
            bodyRef={activityItemsRef}
          />
        )}
      </InfiniteScroll>
    </>
  );
}
