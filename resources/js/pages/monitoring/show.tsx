import { Head, InfiniteScroll } from "@inertiajs/react";
import { useRef } from "react";
import { toast } from "@/components/ui/toast";

import { MonitoringActionsMenu } from "@/components/monitoring/monitoring-actions-menu";
import { MonitoringJobTable } from "@/components/monitoring/monitoring-job-table";
import { MonitoringTabs } from "@/components/monitoring/monitoring-tabs";
import { Badge } from "@/components/ui/badge";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useAutoLoad } from "@/hooks/use-auto-load";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { copyToClipboard } from "@/lib/clipboard";
import { formatDuration } from "@/lib/format-duration";
import type { MonitoringTagPageProps } from "@/types/monitoring";

const monitoringRefreshProps = ["summary"];

function formatRetention(minutes: number) {
  if (minutes <= 0) {
    return "disabled";
  }

  return formatDuration(minutes * 60, "precise");
}

async function copyTag(tag: string) {
  if (await copyToClipboard(tag)) {
    toast.add({ title: "Tag copied.", type: "success" });

    return;
  }

  toast.add({ title: "Tag could not be copied.", type: "error" });
}

function MonitoringShow({
  horizon,
  tag,
  status,
  summary,
  listRevision,
  jobs,
}: MonitoringTagPageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const jobItemsRef = useRef<HTMLTableSectionElement>(null);
  const refreshedJobs = useAutoLoad({
    enabled: autoLoad,
    prop: "jobs",
    interval: horizon.pollInterval,
    listRevision,
    additionalProps: monitoringRefreshProps,
    scope: `${tag}:${status}`,
    loadedItemCount: jobs.data.length,
  });
  const title = status === "failed" ? `Failed Jobs for "${tag}"` : `Recent Jobs for "${tag}"`;

  return (
    <>
      <Head title={title} />
      <Card>
        <CardHeader className="flex flex-row justify-between gap-4 border-b-0">
          <div className="flex min-w-0 flex-col justify-center gap-1">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <CardTitle className="min-w-0">
                <Tooltip>
                  <TooltipTrigger
                    render={
                      <button
                        type="button"
                        className="inline-flex max-w-full cursor-pointer rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={`Copy tag ${tag}`}
                        onClick={() => void copyTag(tag)}
                      />
                    }
                  >
                    <span className="truncate" title={tag}>
                      {tag}
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>Copy tag</TooltipContent>
                </Tooltip>
              </CardTitle>
              {summary.silenced ? (
                <Badge variant="secondary" className="h-auto px-2 py-px text-[11px]">
                  Silenced
                </Badge>
              ) : null}
            </div>
            <p className="text-[12.5px] text-muted-foreground">
              Retention: {formatRetention(summary.monitoredRetentionMinutes)} for recent jobs,{" "}
              {formatRetention(summary.failedRetentionMinutes)} for failed jobs
            </p>
          </div>
          <CardAction className="flex shrink-0 items-center gap-2 self-center">
            <MonitoringActionsMenu
              tag={tag}
              horizonBaseUrl={horizon.baseUrl}
              trackedCount={summary.trackedCount}
              failedCount={summary.failedCount}
              redirectToIndex
            />
          </CardAction>
        </CardHeader>
        <div className="flex items-center border-b border-separator">
          <MonitoringTabs
            tag={tag}
            status={status}
            horizonBaseUrl={horizon.baseUrl}
            trackedCount={summary.trackedCount}
            failedCount={summary.failedCount}
          />
        </div>
        <CardContent className="p-0">
          <InfiniteScroll
            key={`${tag}:${status}:${jobs.available}`}
            data="jobs"
            itemsElement={jobItemsRef}
            onlyNext
            preserveUrl
            buffer={600}
            params={{ onBefore: refreshedJobs.onBeforeNextPage }}
          >
            <MonitoringJobTable
              jobs={jobs.data}
              status={status}
              horizonBaseUrl={horizon.baseUrl}
              available={jobs.available}
              message={jobs.message}
              hasNewEntries={refreshedJobs.hasNewEntries}
              onLoadNewEntries={refreshedJobs.loadNewEntries}
              bodyRef={jobItemsRef}
            />
          </InfiniteScroll>
        </CardContent>
      </Card>
    </>
  );
}

export default MonitoringShow;
