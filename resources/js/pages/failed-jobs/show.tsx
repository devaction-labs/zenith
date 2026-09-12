import { Head, Link, router } from "@inertiajs/react";
import {
  BracesIcon,
  CircleAlertIcon,
  DatabaseIcon,
  RotateCcwIcon,
  type LucideIcon,
} from "lucide-react";
import { useState } from "react";

import { AttemptTimeline } from "@/components/jobs/attempt-timeline";
import { DetailList, DetailListItem } from "@/components/detail-list";
import { Duration } from "@/components/duration";
import { JobCompositionPanel } from "@/components/jobs/job-composition";
import { FailedJobActionsMenu } from "@/components/jobs/failed-job-actions";
import { JobStatus } from "@/components/jobs/job-status";
import { JobTags } from "@/components/jobs/job-tags";
import { StackTrace } from "@/components/jobs/stack-trace";
import { JsonPayload } from "@/components/payload/json-payload";
import { ResponsiveTabsHeader, type ResponsiveTabItem } from "@/components/responsive-tabs-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent } from "@/components/ui/tabs";
import { show as failedJobShow } from "@/generated/routes/zenith/failed-jobs";
import { show as batchShow } from "@/generated/routes/zenith/batches";
import { show as jobShow } from "@/generated/routes/zenith/jobs";
import { show as queueShow } from "@/generated/routes/zenith/queues";
import { useActiveTabQuery } from "@/hooks/use-active-tab-query";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import { currentQueryParameter } from "@/lib/url-query";
import type { FailedJobDetailPageProps, FailedJobRetry } from "@/types/jobs";

type FailedJobDataTab = "exception" | "context" | "data" | "tags" | "retries";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function timestamp(value: number | null) {
  return value === null ? "—" : dateFormatter.format(value * 1000);
}

function FailedJobShow({ horizon, job }: FailedJobDetailPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(horizon.pollInterval, failedJobRefreshProps, autoLoad);

  const retryOfUrl = job.retryOf
    ? resolveHorizonRoute(failedJobShow(job.retryOf), horizon.baseUrl).url
    : null;
  const batchUrl = job.batchId
    ? resolveHorizonRoute(batchShow(job.batchId), horizon.baseUrl).url
    : null;
  const queueUrl = resolveHorizonRoute(
    queueShow(encodeURIComponent(job.queue)),
    horizon.baseUrl,
  ).url;
  const waitTime =
    job.pushedAt !== null && job.reservedAt !== null
      ? Math.max(0, job.reservedAt - job.pushedAt)
      : null;
  const details: Array<{
    label: string;
    value: React.ReactNode;
    identifier?: boolean;
    testId?: string;
  }> = [
    { label: "Status", value: <JobStatus status="failed" /> },
    {
      label: "ID",
      value: job.id,
      identifier: true,
      testId: "failed-job-id",
    },
    { label: "Connection", value: job.connection },
    {
      label: "Queue",
      value: (
        <Link
          className="text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
          href={queueUrl}
          prefetch
        >
          {job.queue}
        </Link>
      ),
    },
    { label: "Attempts", value: String(job.attempts) },
    ...(job.batchId && batchUrl
      ? [
          {
            label: "Batch",
            identifier: true,
            testId: "failed-job-batch-id",
            value: (
              <Link
                className="text-sm text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
                href={batchUrl}
                prefetch
              >
                {job.batchId}
              </Link>
            ),
          },
        ]
      : []),
    ...(retryOfUrl
      ? [
          {
            label: "Retry of ID",
            identifier: true,
            testId: "failed-job-retry-id",
            value: (
              <Link
                className="text-sm text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
                href={retryOfUrl}
                prefetch
                title={job.retryOf ?? undefined}
              >
                {job.retryOf}
              </Link>
            ),
          },
        ]
      : []),
    { label: "Created at", value: timestamp(job.pushedAt) },
    ...(job.scheduledAt !== null
      ? [{ label: "Scheduled at", value: timestamp(job.scheduledAt) }]
      : []),
    ...(job.originalScheduledAt !== null && job.originalScheduledAt !== job.scheduledAt
      ? [{ label: "Originally scheduled at", value: timestamp(job.originalScheduledAt) }]
      : []),
    ...(job.reservedAt !== null
      ? [{ label: "Reserved at", value: timestamp(job.reservedAt) }]
      : []),
    ...(waitTime !== null
      ? [
          {
            label: "Wait time",
            value: <Duration seconds={waitTime} format="precise" showRawValue />,
          },
        ]
      : []),
    { label: "Failed at", value: timestamp(job.failedAt) },
    ...(job.runtime !== null
      ? [
          {
            label: "Runtime",
            value: <Duration seconds={job.runtime} format="precise" showRawValue />,
          },
        ]
      : []),
  ];

  return (
    <>
      <Head title="Failed Job Detail" />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <Card>
          <CardHeader className="flex flex-row justify-between gap-4">
            <CardTitle className="min-w-0 truncate" title={job.name}>
              {job.name}
            </CardTitle>
            <CardAction className="flex shrink-0 items-center self-center">
              <FailedJobActionsMenu
                jobId={job.id}
                horizonBaseUrl={horizon.baseUrl}
                canRetry={job.retryEligible}
              />
            </CardAction>
          </CardHeader>
          <CardContent className="p-0">
            <DetailList>
              {details.map((detail, index) => {
                const title = typeof detail.value === "string" ? detail.value : undefined;

                return (
                  <DetailListItem
                    key={detail.label}
                    label={detail.label}
                    bordered={index > 0}
                    scrollable={detail.identifier}
                    valueClassName={detail.identifier ? "text-sm" : "break-all"}
                    valueTestId={detail.testId}
                    valueTitle={title}
                  >
                    {detail.value}
                  </DetailListItem>
                );
              })}
            </DetailList>
          </CardContent>
        </Card>

        <JobCompositionPanel composition={job.composition} />
        <FailedJobDataTabs job={job} horizonBaseUrl={horizon.baseUrl} />
        <AttemptTimeline timeline={job.attemptTimeline} />
      </div>
    </>
  );
}

function FailedJobDataTabs({
  job,
  horizonBaseUrl,
}: Pick<FailedJobDetailPageProps, "job"> & { horizonBaseUrl: string }) {
  const [activeTab, setActiveTab] = useState<FailedJobDataTab>(initialFailedJobDataTab);
  const hasContext = Object.keys(job.context).length > 0;
  const hasData = Object.keys(job.payload).length > 0;
  const tabItems: readonly ResponsiveTabItem<FailedJobDataTab>[] = [
    { value: "exception", label: "Exception" },
    { value: "context", label: "Context" },
    { value: "data", label: "Data" },
    { value: "tags", label: "Tags", count: job.tags.length },
    { value: "retries", label: "Retries", count: job.retriedBy.length },
  ];

  useActiveTabQuery(activeTab);

  return (
    <Card>
      <CardContent className="p-0">
        <Tabs
          value={activeTab}
          onValueChange={(value) => setActiveTab(value as FailedJobDataTab)}
          className="gap-0"
        >
          <div className="sticky top-0 z-10 border-b border-separator bg-card">
            <ResponsiveTabsHeader
              value={activeTab}
              items={tabItems}
              ariaLabel="Failed job data"
              onValueChange={(value) => {
                if (value !== null && value !== activeTab) {
                  setActiveTab(value);
                }
              }}
            />
          </div>

          <TabsContent value="exception" className="mt-0">
            {job.exception.trim() ? (
              <StackTrace value={job.exception} />
            ) : (
              <DataEmptyState
                icon={CircleAlertIcon}
                title="No exception details"
                description="No exception message was retained for this failed job."
              />
            )}
          </TabsContent>

          <TabsContent value="context" className="mt-0">
            {hasContext ? (
              <JsonPayload value={job.context} copyLabel="exception context" />
            ) : (
              <DataEmptyState
                icon={BracesIcon}
                title="No exception context"
                description="No additional exception context was retained."
              />
            )}
          </TabsContent>

          <TabsContent value="data" className="mt-0">
            {hasData ? (
              <JsonPayload value={job.payload} />
            ) : (
              <DataEmptyState
                icon={DatabaseIcon}
                title="No job data"
                description="No payload data was retained for this job."
              />
            )}
          </TabsContent>

          <TabsContent value="tags" className="mt-0 px-4 py-4 sm:px-6">
            <JobTags tags={job.tags} />
          </TabsContent>

          <TabsContent value="retries" className="mt-0">
            {job.retriedBy.length > 0 ? (
              <RecentRetries retries={job.retriedBy} horizonBaseUrl={horizonBaseUrl} />
            ) : (
              <DataEmptyState
                icon={RotateCcwIcon}
                title="No retry attempts"
                description="This failed job has not been retried."
              />
            )}
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
}

function initialFailedJobDataTab(): FailedJobDataTab {
  const requestedTab = currentQueryParameter("tab");

  if (
    requestedTab === "exception" ||
    requestedTab === "context" ||
    requestedTab === "data" ||
    requestedTab === "tags" ||
    requestedTab === "retries"
  ) {
    return requestedTab;
  }

  return "exception";
}

function DataEmptyState({
  icon: Icon,
  title,
  description,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
}) {
  return (
    <Empty className="min-h-48">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <Icon aria-hidden />
        </EmptyMedia>
        <EmptyTitle>{title}</EmptyTitle>
        <EmptyDescription>{description}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}

function RecentRetries({
  retries,
  horizonBaseUrl,
}: {
  retries: readonly FailedJobRetry[];
  horizonBaseUrl: string;
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="bg-thead text-xs text-muted-foreground">Status</TableHead>
          <TableHead className="bg-thead text-xs text-muted-foreground">ID</TableHead>
          <TableHead className="bg-thead text-right text-xs text-muted-foreground">
            Retry Time
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {retries.map((retry) => {
          const detailUrl = retryDetailUrl(retry, horizonBaseUrl);

          return (
            <TableRow
              className={detailUrl ? "cursor-pointer" : undefined}
              key={retry.id}
              onClick={(event) => {
                if (!detailUrl || isInteractiveTarget(event.target)) {
                  return;
                }

                router.visit(detailUrl);
              }}
              onMouseEnter={() => {
                if (detailUrl) {
                  router.prefetch(detailUrl);
                }
              }}
            >
              <TableCell>
                <RetryStatus status={retry.status} />
              </TableCell>
              <TableCell>
                {detailUrl ? (
                  <Link className="text-foreground hover:underline" href={detailUrl} prefetch>
                    {retry.id}
                  </Link>
                ) : (
                  retry.id
                )}
              </TableCell>
              <TableCell className="text-right text-muted-foreground">
                {timestamp(retry.retriedAt)}
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}

function retryDetailUrl(retry: FailedJobRetry, horizonBaseUrl: string): string | null {
  if (retry.status === "failed") {
    return resolveHorizonRoute(failedJobShow(retry.id), horizonBaseUrl).url;
  }

  if (retry.status === "pending" || retry.status === "reserved") {
    return resolveHorizonRoute(jobShow({ type: "pending", job: retry.id }), horizonBaseUrl).url;
  }

  if (retry.status === "completed") {
    return resolveHorizonRoute(jobShow({ type: "completed", job: retry.id }), horizonBaseUrl).url;
  }

  return null;
}

function RetryStatus({ status }: { status: string }) {
  if (status === "completed") {
    return <Badge variant="success">Completed</Badge>;
  }

  if (status === "failed") {
    return <Badge variant="destructive">Failed</Badge>;
  }

  return (
    <Badge variant="warning">
      {status[0]?.toUpperCase()}
      {status.slice(1)}
    </Badge>
  );
}

const failedJobRefreshProps = ["job"];

export default FailedJobShow;
