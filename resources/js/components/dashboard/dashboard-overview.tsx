import { Link } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";

import { ProgressRing } from "@/components/batches/progress-ring";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Statistic,
  StatisticDetail,
  StatisticDetails,
  StatisticGrid,
  StatisticLabel,
  StatisticLink,
  StatisticUnit,
  StatisticValue,
} from "@/components/ui/statistic";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { formatDuration } from "@/lib/format-duration";
import { livePendingTotal } from "@/lib/live-pending-total";
import type { DashboardSummary } from "@/types/dashboard";

const numberFormatter = new Intl.NumberFormat(undefined, {
  maximumFractionDigits: 2,
});

function formatRetention(minutes: number) {
  if (minutes <= 0) {
    return "no retained history";
  }

  return formatDuration(minutes * 60, "precise");
}

export function completedRetentionTooltip(minutes: number): string {
  if (minutes <= 0) {
    return "Completed jobs have no retained history according to Horizon's configured trim settings.";
  }

  return `Completed jobs are retained for ${formatRetention(minutes)} according to Horizon's configured trim settings.`;
}

export const THROUGHPUT_SINCE_SNAPSHOT_TOOLTIP =
  "Jobs processed since Horizon's most recent metrics snapshot.";

export const SILENCED_JOBS_TOOLTIP =
  "Silenced completions use the same retention window as completed jobs.";

export const AVERAGE_RUNTIME_SINCE_SNAPSHOT_TOOLTIP =
  "Average runtime for jobs processed on this queue since Horizon's most recent metrics snapshot.";

export function CompletedJobsValue({
  value,
  retentionMinutes,
}: {
  value: React.ReactNode;
  retentionMinutes: number;
}) {
  return (
    <Tooltip>
      <TooltipTrigger
        render={
          <span
            className="cursor-help rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            tabIndex={0}
          />
        }
      >
        {typeof value === "number" ? numberFormatter.format(value) : value}
      </TooltipTrigger>
      <TooltipContent side="top">{completedRetentionTooltip(retentionMinutes)}</TooltipContent>
    </Tooltip>
  );
}

export function retainedPeriodLabel(retentionMinutes: number, periodMinutes: 60 | 1440) {
  if (retentionMinutes >= periodMinutes) {
    return periodMinutes === 60 ? "Past hour" : "Past 24 hours";
  }

  return retentionMinutes <= 0
    ? "Retained history"
    : `Retained ${formatRetention(retentionMinutes)}`;
}

/** Readable retention window for overview period labels (Hour / N Minutes / Day / …). */
export function determinePeriod(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return "Hour";
  }

  if (minutes < 60) {
    return `${Math.round(minutes)} Minutes`;
  }

  if (minutes === 60) {
    return "Hour";
  }

  if (minutes < 1440) {
    const hours = Math.round(minutes / 60);

    return hours === 1 ? "Hour" : `${hours} Hours`;
  }

  if (minutes === 1440) {
    return "Day";
  }

  const days = Math.round(minutes / 1440);

  return days === 1 ? "Day" : `${days} Days`;
}

export function OverviewDetail({
  label,
  value,
  tooltip,
}: {
  label: string;
  value: number | string | null;
  tooltip?: string;
}) {
  const detail = (
    <StatisticDetail>
      <span className="text-muted-foreground">{label}</span>
      <span className="font-normal text-muted-foreground">
        {value === null ? "—" : typeof value === "number" ? numberFormatter.format(value) : value}
      </span>
    </StatisticDetail>
  );

  if (!tooltip) {
    return detail;
  }

  return (
    <Tooltip>
      <TooltipTrigger render={<div />}>{detail}</TooltipTrigger>
      <TooltipContent side="top">{tooltip}</TooltipContent>
    </Tooltip>
  );
}

export function OverviewStatLink({
  href,
  title,
  value,
  unit,
  children,
}: {
  href: string;
  title: string;
  value: React.ReactNode;
  unit?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <StatisticLink href={href} prefetch>
      <StatisticLabel>{title}</StatisticLabel>
      <StatisticValue>
        {typeof value === "number" ? numberFormatter.format(value) : value}
        {typeof unit === "string" ? <StatisticUnit>{unit}</StatisticUnit> : (unit ?? null)}
      </StatisticValue>
      <StatisticDetails>{children}</StatisticDetails>
    </StatisticLink>
  );
}

export function DashboardOverview({
  summary,
  links,
}: {
  summary: DashboardSummary;
  links: {
    pending: string;
    failed: string;
    completed: string;
    batches: string;
    batch: (id: string) => string;
  };
}) {
  if (!summary.available) {
    return (
      <Alert variant="destructive">
        <TriangleAlertIcon aria-hidden="true" />
        <AlertTitle>Horizon connection interrupted</AlertTitle>
        <AlertDescription>
          {summary.message ?? "Horizon data is currently unavailable."}
        </AlertDescription>
      </Alert>
    );
  }

  const showBatches = summary.batchesAvailable !== false;
  const failedPeriodLabel = `Past ${determinePeriod(summary.recentlyFailedPeriodMinutes)}`;
  const pendingTotal = livePendingTotal(
    summary.pendingReserved,
    summary.pendingReadyNow,
    summary.pendingDelayed,
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle>Overview</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        <StatisticGrid
          className={
            showBatches ? "sm:grid-cols-2 md:grid-cols-4" : "sm:grid-cols-2 md:grid-cols-3"
          }
        >
          <OverviewStatLink
            href={links.pending}
            title="Pending Jobs"
            value={pendingTotal === null ? "—" : pendingTotal}
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
          <OverviewStatLink href={links.failed} title="Failed Jobs" value={summary.failedJobs}>
            <OverviewDetail label="Past hour" value={summary.failedJobsPastHour} />
            <OverviewDetail label="Past 24 hours" value={summary.failedJobsPastDay} />
            <OverviewDetail label={failedPeriodLabel} value={summary.recentlyFailedJobs} />
          </OverviewStatLink>
          <OverviewStatLink
            href={links.completed}
            title="Completed Jobs"
            value={
              <CompletedJobsValue
                value={summary.completedJobs}
                retentionMinutes={summary.completedRetentionMinutes}
              />
            }
          >
            <OverviewDetail label="Jobs per minute" value={summary.jobsPerMinute} />
            <OverviewDetail
              label="Throughput"
              value={summary.processedSinceSnapshot}
              tooltip={THROUGHPUT_SINCE_SNAPSHOT_TOOLTIP}
            />
            <OverviewDetail
              label="Silenced Jobs"
              value={summary.silencedJobs}
              tooltip={SILENCED_JOBS_TOOLTIP}
            />
          </OverviewStatLink>
          {showBatches ? (
            <Statistic className="outline-none transition-colors hover:bg-table-row-hover">
              <Link
                href={links.batches}
                prefetch
                className="block outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <StatisticLabel>Batches in progress</StatisticLabel>
                <StatisticValue>
                  {summary.activeBatches === null
                    ? "—"
                    : numberFormatter.format(summary.activeBatches)}
                  {summary.activeBatches === null ? (
                    <StatisticUnit>history incomplete</StatisticUnit>
                  ) : null}
                </StatisticValue>
              </Link>
              <StatisticDetails>
                {summary.batchPreviews.slice(0, 3).map((batch) => (
                  <Link
                    className="flex items-center justify-between gap-2 border-t border-dashed border-separator py-[7px] text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    href={links.batch(batch.id)}
                    key={batch.id}
                    prefetch
                  >
                    <span className="truncate text-muted-foreground">{batch.name}</span>
                    <ProgressRing
                      className="shrink-0 gap-1.5 [&_span]:text-[13px]"
                      value={batch.progress}
                    />
                  </Link>
                ))}
              </StatisticDetails>
            </Statistic>
          ) : null}
        </StatisticGrid>
      </CardContent>
    </Card>
  );
}
