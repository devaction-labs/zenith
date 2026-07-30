import { Head, Link } from "@inertiajs/react";
import { useState } from "react";

import { DetailList, DetailListItem } from "@/components/detail-list";
import { Duration } from "@/components/duration";
import { JobStatus, type JobStatusValue } from "@/components/jobs/job-status";
import { PendingJobActionsMenu } from "@/components/jobs/pending-job-actions";
import { JobTags } from "@/components/jobs/job-tags";
import { JsonPayload } from "@/components/payload/json-payload";
import { ResponsiveTabsHeader, type ResponsiveTabItem } from "@/components/responsive-tabs-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent } from "@/components/ui/tabs";
import { show as batchShow } from "@/generated/routes/horizon-new-dawn/batches";
import { show as failedJobShow } from "@/generated/routes/horizon-new-dawn/failed-jobs";
import { show as queueShow } from "@/generated/routes/horizon-new-dawn/queues";
import { useActiveTabQuery } from "@/hooks/use-active-tab-query";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useScheduledJobClock } from "@/hooks/use-scheduled-job-clock";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { pendingJobState } from "@/lib/pending-job-state";
import { currentQueryParameter } from "@/lib/url-query";
import type { JobDetailPageProps } from "@/types/jobs";

type JobDataTab = "data" | "tags";

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

function JobShow({ horizon, type, job }: JobDetailPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(horizon.pollInterval, jobRefreshProps, autoLoad);

  const now = useScheduledJobClock([job]);
  const status = jobDetailStatus(type, job, now);
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
    { label: "Status", value: <JobStatus status={status} /> },
    { label: "ID", value: job.id, identifier: true, testId: "job-id" },
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
    { label: "Tries", value: String(job.attempts) },
    ...(retryOfUrl
      ? [
          {
            label: "Retry of ID",
            identifier: true,
            testId: "job-retry-id",
            value: (
              <Link
                className="text-[13px] text-sm! text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
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
    ...(job.batchId && batchUrl
      ? [
          {
            label: "Batch",
            identifier: true,
            testId: "job-batch-id",
            value: (
              <Link
                className="text-[13px] text-sm! text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
                href={batchUrl}
                prefetch
              >
                {job.batchId}
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
    ...(status === "failed" && job.failedAt !== null
      ? [{ label: "Failed at", value: timestamp(job.failedAt) }]
      : []),
    ...((status === "completed" || status === "silenced") && job.completedAt !== null
      ? [{ label: "Completed at", value: timestamp(job.completedAt) }]
      : []),
    ...(status === "reserved" && job.runtime !== null
      ? [
          {
            label: "Processing for",
            value: <Duration seconds={job.runtime} format="precise" showRawValue />,
          },
        ]
      : []),
    ...((status === "failed" || status === "completed" || status === "silenced") &&
    job.runtime !== null
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
      <Head title="Job Detail" />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <Card>
          <CardHeader className="flex flex-row justify-between gap-4">
            <CardTitle className="flex min-w-0 items-center gap-2">
              <span className="truncate" title={job.name}>
                {job.name}
              </span>
              {job.retryOf ? <Badge variant="retry">Retry</Badge> : null}
            </CardTitle>
            {type === "pending" && job.status === "pending" && job.batchId === null ? (
              <CardAction className="flex shrink-0 items-center self-center">
                <PendingJobActionsMenu
                  jobId={job.id}
                  horizonBaseUrl={horizon.baseUrl}
                  canCancel={job.batchId === null}
                  canRelease={status === "delayed"}
                />
              </CardAction>
            ) : null}
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
                    valueClassName={detail.identifier ? "text-[13px] text-sm!" : "break-all"}
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

        <JobDataTabs payload={job.payload} tags={job.tags} />
      </div>
    </>
  );
}

function jobDetailStatus(
  type: JobDetailPageProps["type"],
  job: JobDetailPageProps["job"],
  now: number,
): JobStatusValue {
  if (job.status === "completed") {
    return type === "silenced" ? "silenced" : "completed";
  }

  if (job.status === "failed") {
    return "failed";
  }

  if (job.status === "reserved") {
    return "reserved";
  }

  if (type === "completed") {
    return "completed";
  }

  if (type === "silenced") {
    return "silenced";
  }

  return pendingJobState(job, now);
}

function JobDataTabs({
  payload,
  tags,
}: {
  payload: Record<string, unknown>;
  tags: readonly string[];
}) {
  const [activeTab, setActiveTab] = useState<JobDataTab>(initialJobDataTab);
  const tabItems: readonly ResponsiveTabItem<JobDataTab>[] = [
    { value: "data", label: "Data" },
    { value: "tags", label: "Tags", count: tags.length },
  ];

  useActiveTabQuery(activeTab);

  return (
    <Card>
      <CardContent className="p-0">
        <Tabs
          value={activeTab}
          onValueChange={(value) => setActiveTab(value as JobDataTab)}
          className="gap-0"
        >
          <div className="border-b border-separator">
            <ResponsiveTabsHeader
              value={activeTab}
              items={tabItems}
              ariaLabel="Job data"
              onValueChange={(value) => {
                if (value !== null && value !== activeTab) {
                  setActiveTab(value);
                }
              }}
            />
          </div>

          <TabsContent value="data" className="mt-0">
            <JsonPayload value={payload} />
          </TabsContent>
          <TabsContent value="tags" className="mt-0 px-4 py-4 sm:px-6">
            <JobTags tags={tags} />
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
}

function initialJobDataTab(): JobDataTab {
  return currentQueryParameter("tab") === "tags" ? "tags" : "data";
}

const jobRefreshProps = ["job"];

export default JobShow;
