import { Head, Link } from "@inertiajs/react";
import { useEffect, useState } from "react";

import { BatchPendingJobsActions } from "@/components/batches/batch-actions";
import { BatchJobsTabs } from "@/components/batches/batch-jobs-tabs";
import { ProgressRing } from "@/components/batches/progress-ring";
import { DetailList, DetailListItem } from "@/components/detail-list";
import { Duration } from "@/components/duration";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { show as queueShow } from "@/generated/routes/zenith/queues";
import { useActiveTabQuery } from "@/hooks/use-active-tab-query";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { currentQueryParameter } from "@/lib/url-query";
import type { BatchDetail, BatchDetailPageProps, BatchJobTab } from "@/types/batches";

const numberFormatter = new Intl.NumberFormat();
const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function BatchShow({ horizon, batch }: BatchDetailPageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const canCancel =
    batch.status !== "cancelled" && batch.status !== "finished" && batch.jobs.pending.total > 0;

  usePageRefresh(horizon.pollInterval, batchRefreshProps, autoLoad);
  const [activeTab, setActiveTab] = useState<BatchJobTab>(() => initialJobTab(batch));
  useActiveTabQuery(activeTab);

  useEffect(() => {
    if (activeTab === "pending" && batch.jobs.pending.total === 0) {
      setActiveTab(batch.jobs.failed.total > 0 ? "failed" : "completed");
    }
  }, [activeTab, batch.jobs.failed.total, batch.jobs.pending.total]);

  return (
    <>
      <Head title={batch.displayName} />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <Card>
          <CardHeader>
            <CardTitle className="truncate" title={batch.displayName}>
              {batch.displayName}
            </CardTitle>
            <CardAction>
              <BatchPendingJobsActions
                batchId={batch.id}
                horizonBaseUrl={horizon.baseUrl}
                canCancel={canCancel}
                canRetry={batch.jobs.failed.total > 0}
                ariaLabel="Batch actions"
              />
            </CardAction>
          </CardHeader>
          <CardContent className="p-0">
            <BatchDetails batch={batch} horizonBaseUrl={horizon.baseUrl} />
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-0">
            <BatchJobsTabs
              batchId={batch.id}
              value={activeTab}
              onValueChange={setActiveTab}
              jobs={batch.jobs}
              horizonBaseUrl={horizon.baseUrl}
            />
          </CardContent>
        </Card>
      </div>
    </>
  );
}

function initialJobTab(batch: BatchDetail): BatchJobTab {
  const requestedTab =
    typeof window !== "undefined" &&
    window.location.pathname.endsWith(`/batches/${encodeURIComponent(batch.id)}`)
      ? currentQueryParameter("tab")
      : null;

  if (requestedTab === "pending" && batch.jobs.pending.total > 0) {
    return requestedTab;
  }

  if (requestedTab === "completed" || requestedTab === "failed") {
    return requestedTab;
  }

  if (batch.jobs.completed.total > 0 && batch.jobs.pending.total === 0) {
    return "completed";
  }

  if (batch.jobs.failed.total > 0) {
    return "failed";
  }

  if (batch.jobs.pending.total > 0) {
    return "pending";
  }

  return "completed";
}

function BatchDetails({ batch, horizonBaseUrl }: { batch: BatchDetail; horizonBaseUrl: string }) {
  const queueUrl = batch.queue
    ? resolveHorizonRoute(queueShow(encodeURIComponent(batch.queue)), horizonBaseUrl).url
    : null;
  const timings = batchTimings(batch);
  const details: Array<{
    label: string;
    value: React.ReactNode;
    href?: string;
    identifier?: boolean;
  }> = [
    {
      label: "Progress",
      value: (
        <ProgressRing
          value={batch.progress}
          cancelled={batch.status === "cancelled"}
          pendingJobs={batch.pendingJobs}
          failedJobs={batch.failedJobs}
        />
      ),
    },
    { label: "ID", value: batch.id, identifier: true },
    ...(batch.name ? [{ label: "Name", value: batch.name }] : []),
    {
      label: "Connection",
      value: attributionValue(
        batch.connection,
        batch.connectionExplicit === true,
        batch.attributionCaptured === true,
      ),
    },
    {
      label: "Queue",
      value: attributionValue(
        batch.queue,
        batch.queueExplicit === true,
        batch.attributionCaptured === true,
      ),
      href:
        (batch.queueExplicit === true || batch.attributionCaptured === true) && queueUrl
          ? queueUrl
          : undefined,
    },
    { label: "Total jobs", value: numberFormatter.format(batch.totalJobs) },
    { label: "Pending jobs", value: numberFormatter.format(batch.jobs.pending.total) },
    { label: "Failed job attempts", value: numberFormatter.format(batch.failedJobAttempts) },
    { label: "Queued at", value: dateFormatter.format(timings.queuedAt * 1000) },
    {
      label: "Wait time",
      value: timingValue(timings.waitTime, timings.unavailableReason),
    },
    {
      label: "Runtime",
      value: timingValue(timings.runtime, timings.unavailableReason),
    },
    ...(batch.finishedAt
      ? [{ label: "Finished at", value: dateFormatter.format(batch.finishedAt * 1000) }]
      : []),
    ...(batch.cancelledAt
      ? [{ label: "Cancelled at", value: dateFormatter.format(batch.cancelledAt * 1000) }]
      : []),
  ];

  return (
    <DetailList className="text-sm">
      {details.map((detail, index) => (
        <DetailListItem
          key={detail.label}
          label={detail.label}
          bordered={index > 0}
          scrollable={detail.identifier}
          valueTestId={detail.identifier ? "batch-id" : undefined}
        >
          {detail.href ? (
            <Link
              className="break-all text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
              href={detail.href}
              prefetch
            >
              {detail.value}
            </Link>
          ) : (
            <span className={detail.identifier ? undefined : "break-all"}>{detail.value}</span>
          )}
        </DetailListItem>
      ))}
    </DetailList>
  );
}

function attributionValue(value: string | null, explicit: boolean, captured: boolean): string {
  if (explicit) {
    return value ?? "Not recorded";
  }

  if (value === null) {
    return "Not recorded";
  }

  return captured
    ? `Inferred when first discovered: ${value}`
    : `Not recorded (current default: ${value})`;
}

function batchTimings(batch: BatchDetail): {
  queuedAt: number;
  waitTime: number | null;
  runtime: number | null;
  unavailableReason: string | null;
} {
  const jobLists = Object.values(batch.jobs);

  if (jobLists.some((jobList) => !jobList.complete)) {
    return {
      queuedAt: batch.createdAt,
      waitTime: null,
      runtime: null,
      unavailableReason: "Some job history was trimmed, so this value can't be calculated.",
    };
  }

  const rows = jobLists.flatMap((jobList) => jobList.rows);
  const pushedAt = rows.flatMap((job) => (job.pushedAt === null ? [] : [job.pushedAt]));
  const reservedAt = rows.flatMap((job) => (job.reservedAt === null ? [] : [job.reservedAt]));
  const queuedAt = pushedAt.length > 0 ? Math.min(...pushedAt) : batch.createdAt;
  const startedAt = reservedAt.length > 0 ? Math.min(...reservedAt) : null;

  if (startedAt === null) {
    return {
      queuedAt,
      waitTime: null,
      runtime: null,
      unavailableReason: "No batch job has started processing yet.",
    };
  }

  const finishedAt = batch.finishedAt ?? batch.cancelledAt ?? Date.now() / 1000;

  return {
    queuedAt,
    waitTime: Math.max(0, startedAt - queuedAt),
    runtime: Math.max(0, finishedAt - startedAt),
    unavailableReason: null,
  };
}

function timingValue(value: number | null, unavailableReason: string | null): React.ReactNode {
  if (value !== null) {
    return <Duration seconds={value} format="precise" showRawValue />;
  }

  if (unavailableReason === null) {
    return "—";
  }

  return (
    <Tooltip>
      <TooltipTrigger
        render={
          <span
            className="inline-flex cursor-help rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label={`Unavailable. ${unavailableReason}`}
            tabIndex={0}
          >
            —
          </span>
        }
      />
      <TooltipContent>{unavailableReason}</TooltipContent>
    </Tooltip>
  );
}

const batchRefreshProps = ["batch"];

export default BatchShow;
