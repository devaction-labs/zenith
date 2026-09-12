import { Link, router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { lazy, Suspense } from "react";

import { ProgressRing } from "@/components/batches/progress-ring";
import {
  AVERAGE_RUNTIME_SINCE_SNAPSHOT_TOOLTIP,
  CompletedJobsValue,
  determinePeriod,
  OverviewDetail,
  OverviewStatLink,
  SILENCED_JOBS_TOOLTIP,
  THROUGHPUT_SINCE_SNAPSHOT_TOOLTIP,
} from "@/components/dashboard/dashboard-overview";
import { Duration } from "@/components/duration";
import { QueueActionsMenu, QueuePauseBadge } from "@/components/queues/queue-actions-menu";
import { QueueWaitThresholdMetric } from "@/components/queues/queue-wait-threshold";
import { ResponsiveTabsHeader, type ResponsiveTabItem } from "@/components/responsive-tabs-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Statistic,
  StatisticGrid,
  StatisticLabel,
  StatisticValue,
} from "@/components/ui/statistic";
import { Tabs } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { show as queueShow } from "@/generated/routes/zenith/queues";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { livePendingTotal } from "@/lib/live-pending-total";
import { cn } from "@/lib/utils";
import type { MetricPreview } from "@/types/metrics";
import type { QueueActivityTab, QueueDetailView, QueueSummary } from "@/types/queues";

const numberFormatter = new Intl.NumberFormat(undefined, {
  maximumFractionDigits: 3,
});
let metricChartPromise: Promise<{
  default: typeof import("@/components/metrics/metric-chart").MetricChart;
}> | null = null;
let loadedMetricChart: typeof import("@/components/metrics/metric-chart").MetricChart | null = null;

function loadMetricChart() {
  metricChartPromise ??= import("@/components/metrics/metric-chart").then(({ MetricChart }) => {
    loadedMetricChart = MetricChart;

    return {
      default: MetricChart,
    };
  });

  return metricChartPromise;
}

const MetricChart = lazy(loadMetricChart);

function retainedValue(value: number | null, complete: boolean) {
  if (value === null) {
    return "—";
  }

  return complete ? value : `${numberFormatter.format(value)}+`;
}

function activityUrl(queue: string, tab: QueueActivityTab, horizonBaseUrl: string) {
  const route = queueShow(encodeURIComponent(queue), { query: { tab } });

  return resolveHorizonRoute(route, horizonBaseUrl).url;
}

export function QueueOverview({
  view,
  tab,
  summary,
  preview,
  horizonBaseUrl,
  queuePausing = true,
  timedQueuePausing = true,
  batchAttributionAvailable = false,
  inactive = false,
}: {
  view: QueueDetailView;
  tab: QueueActivityTab;
  summary: QueueSummary;
  preview: MetricPreview | null;
  horizonBaseUrl: string;
  queuePausing?: boolean;
  timedQueuePausing?: boolean;
  batchAttributionAvailable?: boolean;
  inactive?: boolean;
}) {
  if (!summary.available && !inactive) {
    return (
      <Alert variant="destructive">
        <TriangleAlertIcon aria-hidden="true" />
        <AlertTitle>Queue unavailable</AlertTitle>
        <AlertDescription>
          {summary.message ?? "This queue is no longer supervised by Horizon."}
        </AlertDescription>
      </Alert>
    );
  }

  const viewUrl = (nextView: QueueDetailView) => {
    const route = queueShow(encodeURIComponent(summary.name), {
      query: nextView === "metrics" ? { view: nextView, tab } : { tab },
    });

    return resolveHorizonRoute(route, horizonBaseUrl).url;
  };
  const viewItems: readonly ResponsiveTabItem<QueueDetailView>[] = [
    {
      value: "overview",
      label: "Overview",
      render: (
        <Link
          href={viewUrl("overview")}
          only={["view", "preview"]}
          prefetch
          preserveScroll
          preserveState
        />
      ),
    },
    {
      value: "metrics",
      label: "Metrics",
      render: (
        <Link
          href={viewUrl("metrics")}
          only={["view", "preview"]}
          prefetch
          preserveScroll
          preserveState
          onFocus={() => void loadMetricChart()}
          onMouseEnter={() => void loadMetricChart()}
          onClick={(event) => {
            if (loadedMetricChart !== null) {
              return;
            }

            event.preventDefault();

            void loadMetricChart().then(() => {
              router.visit(viewUrl("metrics"), {
                only: ["view", "preview"],
                preserveScroll: true,
                preserveState: true,
              });
            });
          }}
        />
      ),
    },
  ];
  const selectView = (nextView: QueueDetailView | null) => {
    if (!nextView || nextView === view) {
      return;
    }

    const visit = () => {
      router.visit(viewUrl(nextView), {
        only: ["view", "preview"],
        preserveScroll: true,
        preserveState: true,
      });
    };

    if (nextView === "metrics" && loadedMetricChart === null) {
      void loadMetricChart().then(visit);

      return;
    }

    visit();
  };

  return (
    <Card>
      <CardHeader className="border-b-0">
        <CardTitle className="flex min-w-0 items-center gap-2" title={summary.name}>
          <span className="truncate">{summary.name}</span>
          {summary.pauseTargets.map((target) => (
            <QueuePauseBadge
              key={target.connection}
              paused={target.paused}
              pausedUntil={target.pausedUntil}
              connection={summary.pauseTargets.length > 1 ? target.connection : undefined}
            />
          ))}
        </CardTitle>
        {!inactive ? (
          <CardAction>
            <QueueActionsMenu
              queue={summary.name}
              targets={summary.pauseTargets}
              failedJobs={summary.failedJobs}
              horizonBaseUrl={horizonBaseUrl}
              queuePausing={queuePausing}
              timedQueuePausing={timedQueuePausing}
            />
          </CardAction>
        ) : null}
      </CardHeader>
      {summary.routing?.available &&
      (summary.routing.classRoutes.length > 0 || summary.routing.forwardedQueue !== null) ? (
        <div className="border-b border-separator px-6 py-3 text-sm">
          {summary.routing.forwardedQueue ? (
            <p className="text-muted-foreground">
              Forwarded to{" "}
              <span className="text-foreground">{summary.routing.forwardedQueue}</span>
              {summary.routing.forwardedConnection
                ? ` on ${summary.routing.forwardedConnection}`
                : null}
            </p>
          ) : null}
          {summary.routing.classRoutes.length > 0 ? (
            <p className="text-muted-foreground">
              Class routes:{" "}
              {summary.routing.classRoutes.map((route) => route.class.split("\\").pop()).join(", ")}
            </p>
          ) : null}
        </div>
      ) : null}
      <CardContent className="p-0">
        <Tabs value={view} className="gap-0">
          <ResponsiveTabsHeader
            value={view}
            items={viewItems}
            ariaLabel="Queue view"
            separatedFromHeader
            onValueChange={selectView}
            className="border-b border-separator"
            triggerClassName="pt-0 pb-3"
          />
        </Tabs>
        <div className="relative">
          <div
            className={cn(view === "metrics" && "invisible")}
            aria-hidden={view === "metrics" ? true : undefined}
            inert={view === "metrics" ? true : undefined}
          >
            <QueueStatistics
              summary={summary}
              horizonBaseUrl={horizonBaseUrl}
              batchAttributionAvailable={batchAttributionAvailable}
            />
          </div>
          {view === "metrics" ? (
            <div className="absolute inset-0 min-h-0 overflow-hidden">
              <QueueMetrics name={summary.name} preview={preview} />
            </div>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function QueueStatistics({
  summary,
  horizonBaseUrl,
  batchAttributionAvailable,
}: {
  summary: QueueSummary;
  horizonBaseUrl: string;
  batchAttributionAvailable: boolean;
}) {
  const pendingTotal = livePendingTotal(
    summary.pendingReserved,
    summary.pendingReadyNow,
    summary.pendingDelayed,
  );

  return (
    <>
      <StatisticGrid
        className={cn(
          "sm:grid-cols-2",
          batchAttributionAvailable ? "md:grid-cols-4" : "md:grid-cols-3",
        )}
      >
        <OverviewStatLink
          href={activityUrl(summary.name, "pending", horizonBaseUrl)}
          title="Pending Jobs"
          value={pendingTotal === null ? "—" : pendingTotal}
          preserveScroll
        >
          <OverviewDetail
            label="Reserved"
            value={summary.pendingReserved}
            tooltip="Jobs currently being worked on."
          />
          <OverviewDetail
            label="Ready"
            value={summary.pendingReadyNow}
            tooltip="Jobs waiting for an available worker."
          />
          <OverviewDetail
            label="Delayed"
            value={summary.pendingDelayed}
            tooltip="Jobs scheduled to run later."
          />
        </OverviewStatLink>
        <OverviewStatLink
          href={activityUrl(summary.name, "failed", horizonBaseUrl)}
          title="Failed Jobs"
          value={retainedValue(summary.failedJobs, summary.failedComplete)}
          preserveScroll
        >
          <OverviewDetail
            label="Past hour"
            value={retainedValue(summary.failedJobsPastHour, summary.failedJobsPastHourComplete)}
          />
          <OverviewDetail
            label="Past 24 hours"
            value={retainedValue(summary.failedJobsPastDay, summary.failedJobsPastDayComplete)}
          />
          <OverviewDetail
            label={`Past ${determinePeriod(summary.failedRetentionMinutes)}`}
            value={retainedValue(summary.failedJobs, summary.failedComplete)}
          />
        </OverviewStatLink>
        <OverviewStatLink
          href={activityUrl(summary.name, "completed", horizonBaseUrl)}
          title="Completed Jobs"
          value={
            summary.completedJobs === null ? (
              "—"
            ) : (
              <CompletedJobsValue
                value={retainedValue(summary.completedJobs, summary.completedComplete)}
                retentionMinutes={summary.completedRetentionMinutes}
              />
            )
          }
          preserveScroll
        >
          <OverviewDetail label="Jobs per minute" value={summary.jobsPerMinute} />
          <OverviewDetail
            label="Throughput"
            value={summary.throughput}
            tooltip={THROUGHPUT_SINCE_SNAPSHOT_TOOLTIP}
          />
          <OverviewDetail
            label="Silenced Jobs"
            value={retainedValue(summary.silencedJobs, summary.silencedComplete)}
            tooltip={SILENCED_JOBS_TOOLTIP}
          />
        </OverviewStatLink>
        {batchAttributionAvailable ? (
          <OverviewStatLink
            href={activityUrl(summary.name, "batches", horizonBaseUrl)}
            title="Batches in progress"
            value={retainedValue(summary.activeBatches, summary.batchesComplete)}
            preserveScroll
          >
            {summary.batchPreviews.slice(0, 3).map((batch) => (
              <div
                className="flex items-center justify-between gap-2 border-t border-dashed border-separator py-[7px] text-[13px]"
                key={batch.id}
              >
                <span className="truncate text-muted-foreground">{batch.name}</span>
                <ProgressRing
                  className="shrink-0 gap-1.5 [&_span]:text-[13px]"
                  value={batch.progress}
                />
              </div>
            ))}
          </OverviewStatLink>
        ) : null}
      </StatisticGrid>
      <StatisticGrid className="border-t border-separator sm:grid-cols-2 md:grid-cols-4">
        <QueueMetric label="Total Processes" value={summary.processes ?? "—"} />
        {summary.waitThreshold ? (
          <QueueWaitThresholdMetric waitThreshold={summary.waitThreshold} />
        ) : (
          <QueueMetric label="Wait Threshold" value="—" />
        )}
        <QueueMetric
          label="Average Runtime"
          value={
            summary.averageRuntime === null ? (
              "—"
            ) : (
              <Duration seconds={summary.averageRuntime} format="precise" />
            )
          }
          tooltip={AVERAGE_RUNTIME_SINCE_SNAPSHOT_TOOLTIP}
        />
        <QueueMetric
          label="Throughput"
          value={summary.throughput ?? "—"}
          tooltip={THROUGHPUT_SINCE_SNAPSHOT_TOOLTIP}
        />
      </StatisticGrid>
    </>
  );
}

function QueueMetrics({ name, preview }: { name: string; preview: MetricPreview | null }) {
  const snapshots = preview?.data ?? [];
  const LoadedMetricChart = loadedMetricChart;

  return (
    <div className="flex h-full min-h-0 flex-col">
      {preview !== null && !preview.available ? (
        <Alert variant="destructive" className="m-4 shrink-0">
          <TriangleAlertIcon aria-hidden="true" />
          <AlertTitle>Metrics unavailable</AlertTitle>
          <AlertDescription>
            {preview.message ?? "Metrics for this queue are currently unavailable."}
          </AlertDescription>
        </Alert>
      ) : null}
      <div className="grid min-h-0 flex-1 grid-rows-2 gap-px bg-separator md:grid-cols-2 md:grid-rows-1">
        <QueueMetricChart title={`Throughput — ${name}`}>
          {LoadedMetricChart ? (
            <LoadedMetricChart kind="throughput" snapshots={snapshots} />
          ) : (
            <Suspense fallback={<MetricChartSkeleton />}>
              <MetricChart kind="throughput" snapshots={snapshots} />
            </Suspense>
          )}
        </QueueMetricChart>
        <QueueMetricChart title={`Runtime — ${name}`}>
          {LoadedMetricChart ? (
            <LoadedMetricChart kind="runtime" snapshots={snapshots} />
          ) : (
            <Suspense fallback={<MetricChartSkeleton />}>
              <MetricChart kind="runtime" snapshots={snapshots} />
            </Suspense>
          )}
        </QueueMetricChart>
      </div>
    </div>
  );
}

function MetricChartSkeleton() {
  return (
    <div
      data-slot="queue-metric-fallback"
      className="flex h-full min-h-0 flex-col gap-3 px-4 sm:px-6"
      aria-label="Loading queue metrics"
    >
      <Skeleton className="min-h-0 w-full flex-1" />
      <Skeleton className="mx-auto h-3 w-28 shrink-0" />
    </div>
  );
}

function QueueMetricChart({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="flex min-h-0 flex-col bg-card">
      <h2 className="shrink-0 truncate px-4 py-4 text-sm font-medium sm:px-6" title={title}>
        {title}
      </h2>
      <div className="relative min-h-0 flex-1">
        <div
          className={cn(
            "absolute inset-0 flex min-h-0 flex-col overflow-auto px-0 pt-3 pb-2",
            "[&>*]:flex [&>*]:h-full [&>*]:min-h-0 [&>*]:flex-col",
            "[&_[data-slot=chart]]:h-full [&_[data-slot=chart]]:min-h-0 [&_[data-slot=chart]]:aspect-auto",
            "[&_[data-slot=empty]]:h-full [&_[data-slot=empty]]:min-h-0",
            "[&_[data-slot=queue-metric-fallback]]:h-full [&_[data-slot=queue-metric-fallback]]:min-h-0",
          )}
        >
          {children}
        </div>
      </div>
    </section>
  );
}

function QueueMetric({
  label,
  value,
  tooltip,
}: {
  label: string;
  value: React.ReactNode;
  tooltip?: string;
}) {
  return (
    <Statistic>
      <StatisticLabel>
        {tooltip ? (
          <Tooltip>
            <TooltipTrigger
              render={
                <span
                  className="cursor-help rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  tabIndex={0}
                />
              }
            >
              {label}
            </TooltipTrigger>
            <TooltipContent side="top">{tooltip}</TooltipContent>
          </Tooltip>
        ) : (
          label
        )}
      </StatisticLabel>
      <StatisticValue>{value}</StatisticValue>
    </Statistic>
  );
}
