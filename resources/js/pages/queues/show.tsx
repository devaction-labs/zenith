import { Head } from "@inertiajs/react";
import { useEffect, useRef } from "react";

import { QueueActivityTabs } from "@/components/queues/queue-activity-tabs";
import { QueueOverview } from "@/components/queues/queue-overview";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAutoLoad } from "@/hooks/use-auto-load";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import type { QueueActivity, QueueShowPageProps, QueueSummary } from "@/types/queues";

type CompletedSummarySlice = Pick<
  QueueSummary,
  | "completedJobs"
  | "completedComplete"
  | "completedJobsPerMinute"
  | "completedJobsPerMinuteComplete"
  | "completedJobsPastHour"
  | "completedJobsPastHourComplete"
  | "completedJobsPastDay"
  | "completedJobsPastDayComplete"
>;

type QueueCompletedSummary = {
  queue: string;
  summary: CompletedSummarySlice;
};

type ResolvedQueueActivityProps = QueueShowPageProps &
  Required<Pick<QueueShowPageProps, "listRevision" | "activity">>;

const summaryRefreshProps = ["summary"];
const metricSummaryRefreshProps = ["summary", "preview"];

function QueueShow(props: QueueShowPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(
    props.horizon.pollInterval,
    props.view === "metrics" ? metricSummaryRefreshProps : summaryRefreshProps,
    autoLoad,
  );

  return (
    <>
      <Head title={props.queue} />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <QueueOverviewBoundary {...props} />
        <QueueActivityBoundary {...props} autoLoad={autoLoad} />
      </div>
    </>
  );
}

function QueueOverviewBoundary(props: QueueShowPageProps) {
  return <QueueOverviewPanel {...props} summary={props.summary} />;
}

function QueueOverviewPanel({
  horizon,
  queue,
  view,
  summary,
  tab,
  preview,
  batchAttributionAvailable,
}: QueueShowPageProps) {
  const effectiveSummary = useLastKnownCompletedSummary(queue, summary);
  const stoppedQueue = horizon.status === "inactive" && !effectiveSummary.available;

  return (
    <QueueOverview
      view={view}
      tab={tab}
      summary={effectiveSummary}
      preview={preview ?? null}
      horizonBaseUrl={horizon.baseUrl}
      queuePausing={horizon.capabilities?.queuePausing ?? false}
      timedQueuePausing={horizon.capabilities?.timedQueuePausing ?? false}
      batchAttributionAvailable={batchAttributionAvailable ?? false}
      inactive={stoppedQueue}
    />
  );
}

function QueueActivityBoundary(props: QueueShowPageProps & { autoLoad: boolean }) {
  if (!hasQueueActivityData(props)) {
    return <QueueActivityLoading />;
  }

  return <QueueActivityPanel {...props} />;
}

function QueueActivityPanel({
  horizon,
  queue,
  view,
  summary,
  tab,
  querySignature,
  listRevision,
  activity,
  batchAttributionAvailable,
  autoLoad,
}: ResolvedQueueActivityProps & { autoLoad: boolean }) {
  const effectiveSummary = useLastKnownCompletedSummary(queue, summary);
  const stoppedQueue =
    summary !== undefined && horizon.status === "inactive" && !effectiveSummary.available;
  const visibleActivity = stoppedQueue ? stoppedQueueActivity : neutralizeWarmingActivity(activity);
  const refreshedActivity = useAutoLoad({
    enabled: autoLoad,
    prop: "activity",
    interval: horizon.pollInterval,
    cursor: tab === "batches" ? "before_id" : "starting_at",
    listRevision,
    scope: `${querySignature}:${view}`,
    includeSharedProps: false,
    loadedItemCount: activity.data.length,
  });

  return (
    <>
      {effectiveSummary.available || stoppedQueue ? (
        <QueueActivityTabs
          queue={queue}
          tab={tab}
          view={view}
          summary={effectiveSummary}
          activity={visibleActivity}
          horizonBaseUrl={horizon.baseUrl}
          batchAttributionAvailable={batchAttributionAvailable ?? false}
          querySignature={querySignature}
          hasNewEntries={refreshedActivity.hasNewEntries}
          onLoadNewEntries={refreshedActivity.loadNewEntries}
          onBeforeNextPage={refreshedActivity.onBeforeNextPage}
          inactive={stoppedQueue}
        />
      ) : null}
    </>
  );
}

function hasQueueActivityData(props: QueueShowPageProps): props is ResolvedQueueActivityProps {
  return props.listRevision !== undefined && props.activity !== undefined;
}

function QueueActivityLoading() {
  return (
    <Card role="status" aria-label="Loading queue activity">
      <span className="sr-only">Loading queue activity</span>
      <CardContent className="p-0" aria-hidden="true">
        <div className="flex min-h-[50px] items-center gap-6 px-4 sm:min-h-[54px] sm:px-6">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-4 w-20" />
        </div>
        {Array.from({ length: 4 }, (_, index) => (
          <div
            className="flex h-[68px] items-center justify-between border-b border-separator px-4 last:border-b-0 sm:px-6"
            key={index}
          >
            <div>
              <Skeleton className="h-4 w-36" />
              <Skeleton className="mt-2 h-3 w-20" />
            </div>
            <Skeleton className="h-4 w-24" />
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function useLastKnownCompletedSummary(
  queue: string,
  summary: QueueSummary | undefined,
): QueueSummary {
  const lastKnown = useRef<QueueCompletedSummary | null>(null);
  const cachedSummary = lastKnown.current?.queue === queue ? lastKnown.current.summary : null;

  useEffect(() => {
    if (summary === undefined) {
      return;
    }

    if (!summary.available) {
      lastKnown.current = null;

      return;
    }

    if (summary.completedAvailable) {
      lastKnown.current = {
        queue,
        summary: completedSummarySlice(summary),
      };

      return;
    }

    if (lastKnown.current?.queue !== queue) {
      lastKnown.current = null;
    }
  }, [queue, summary]);

  if (summary?.available && summary.completedAvailable) {
    return summary;
  }

  const resolvedSummary = summary ?? loadingQueueSummary(queue);

  return {
    ...resolvedSummary,
    ...(resolvedSummary.available && cachedSummary ? cachedSummary : unavailableCompletedSummary),
  };
}

function completedSummarySlice(summary: QueueSummary): CompletedSummarySlice {
  return {
    completedJobs: summary.completedJobs,
    completedComplete: summary.completedComplete,
    completedJobsPerMinute: summary.completedJobsPerMinute,
    completedJobsPerMinuteComplete: summary.completedJobsPerMinuteComplete,
    completedJobsPastHour: summary.completedJobsPastHour,
    completedJobsPastHourComplete: summary.completedJobsPastHourComplete,
    completedJobsPastDay: summary.completedJobsPastDay,
    completedJobsPastDayComplete: summary.completedJobsPastDayComplete,
  };
}

const unavailableCompletedSummary: CompletedSummarySlice = {
  completedJobs: null,
  completedComplete: false,
  completedJobsPerMinute: null,
  completedJobsPerMinuteComplete: false,
  completedJobsPastHour: null,
  completedJobsPastHourComplete: false,
  completedJobsPastDay: null,
  completedJobsPastDayComplete: false,
};
const loadingQueueSummaryDefaults = {
  available: true,
  connections: [],
  pauseTargets: [],
  pendingJobs: null,
  pendingComplete: false,
  retainedJobsWarming: true,
  pendingReserved: null,
  pendingReadyNow: null,
  pendingDelayed: null,
  failedJobs: null,
  failedComplete: false,
  failedJobsPerMinute: null,
  failedJobsPerMinuteComplete: false,
  failedJobsPastHour: null,
  failedJobsPastHourComplete: false,
  failedJobsPastDay: null,
  failedJobsPastDayComplete: false,
  failedRetentionMinutes: 0,
  completedJobs: null,
  completedComplete: false,
  completedAvailable: false,
  completedJobsPerMinute: null,
  completedJobsPerMinuteComplete: false,
  completedJobsPastHour: null,
  completedJobsPastHourComplete: false,
  completedJobsPastDay: null,
  completedJobsPastDayComplete: false,
  completedRetentionMinutes: 0,
  silencedJobs: null,
  silencedComplete: false,
  batches: null,
  activeBatches: null,
  batchesComplete: false,
  batchPreviews: [],
  processes: null,
  waitThreshold: null,
  jobsPerMinute: null,
  throughput: null,
  averageRuntime: null,
  message: null,
} satisfies Omit<QueueSummary, "name">;
const stoppedQueueActivity = {
  data: [],
  total: 0,
  complete: true,
  available: true,
  message: null,
  warming: false,
} satisfies QueueActivity;

function loadingQueueSummary(queue: string): QueueSummary {
  return {
    ...loadingQueueSummaryDefaults,
    name: queue,
  };
}

function neutralizeWarmingActivity(activity: QueueActivity): QueueActivity {
  if (!activity.warming) {
    return activity;
  }

  return {
    ...activity,
    available: true,
    message: null,
  };
}

export default QueueShow;
