import { router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { type Ref } from "react";

import { NewEntriesTableRow } from "@/components/data-table/new-entries-alert";
import { SortableTableHead } from "@/components/data-table/sortable-table-head";
import { TableEmpty } from "@/components/data-table/table-empty";
import { Duration } from "@/components/duration";
import { FailedJobActionsMenu } from "@/components/jobs/failed-job-actions";
import { JobTablePrimaryCell } from "@/components/jobs/job-table-primary-cell";
import { MonitoringNavigationIcon } from "@/components/navigation-icons";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Table, TableBody, TableCell, TableHeader, TableRow } from "@/components/ui/table";
import { show as failedJobShow } from "@/generated/routes/zenith/failed-jobs";
import { show as jobShow } from "@/generated/routes/zenith/jobs";
import { useScheduledJobClock } from "@/hooks/use-scheduled-job-clock";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import { pendingJobState } from "@/lib/pending-job-state";
import type { JobRow } from "@/types/jobs";
import type { MonitoringStatus } from "@/types/monitoring";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function formatTimestamp(timestamp: number | null) {
  return timestamp === null ? "—" : dateFormatter.format(timestamp * 1000);
}

export function MonitoringJobTable({
  jobs,
  status,
  horizonBaseUrl,
  available = true,
  message = null,
  hasNewEntries = false,
  onLoadNewEntries,
  bodyRef,
}: {
  jobs: readonly JobRow[];
  status: MonitoringStatus;
  horizonBaseUrl: string;
  available?: boolean;
  message?: string | null;
  hasNewEntries?: boolean;
  onLoadNewEntries?: () => void;
  bodyRef?: Ref<HTMLTableSectionElement>;
}) {
  const now = useScheduledJobClock(jobs);
  const failed = status === "failed";

  if (!available) {
    return (
      <Alert variant="destructive" className="m-4">
        <TriangleAlertIcon aria-hidden="true" />
        <AlertTitle>Tagged jobs unavailable</AlertTitle>
        <AlertDescription>
          {message ?? "Jobs for this tag are currently unavailable."}
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <>
      <Table>
        <TableHeader className="sticky top-0 z-10">
          <TableRow>
            <SortableTableHead label="Job" />
            <SortableTableHead label="Queued At" className="w-[210px]" />
            {failed ? (
              <SortableTableHead label="Failed At" className="w-[210px]" />
            ) : (
              <SortableTableHead label="Runtime" className="w-[110px] text-right" />
            )}
            {failed ? (
              <SortableTableHead
                label="Actions"
                className="w-[80px] pr-2.5 pl-3 text-right sm:pr-6"
              />
            ) : null}
          </TableRow>
        </TableHeader>
        <TableBody role="presentation">
          {hasNewEntries && onLoadNewEntries ? (
            <NewEntriesTableRow columns={failed ? 4 : 3} onLoad={onLoadNewEntries} />
          ) : null}
          {jobs.length === 0 ? (
            <TableEmpty
              columns={failed ? 4 : 3}
              title="No jobs for this tag"
              description="No jobs for this tag yet. History starts after jobs use this exact tag."
              icon={MonitoringNavigationIcon}
            />
          ) : null}
        </TableBody>
        <TableBody ref={bodyRef}>
          {jobs.map((job) => {
            const failedDetail = failed || job.status === "failed";
            const detailUrl = failedDetail
              ? resolveHorizonRoute(failedJobShow(job.id), horizonBaseUrl).url
              : resolveHorizonRoute(
                  jobShow({
                    type: job.status === "completed" ? "completed" : "pending",
                    job: job.id,
                  }),
                  horizonBaseUrl,
                ).url;
            const delayed = job.status === "pending" && pendingJobState(job, now) === "delayed";

            return (
              <TableRow
                className="cursor-pointer"
                key={job.id}
                onClick={(event) => {
                  if (isInteractiveTarget(event.target)) {
                    return;
                  }

                  router.visit(detailUrl);
                }}
                onMouseEnter={() => router.prefetch(detailUrl)}
              >
                <JobTablePrimaryCell
                  name={job.shortName}
                  fullName={job.name}
                  queue={job.queue}
                  tags={job.tags}
                  href={detailUrl}
                  tagLimit={3}
                  accessory={delayed ? <Badge variant="delayed">Delayed</Badge> : undefined}
                />
                <TableCell className="text-muted-foreground">
                  {formatTimestamp(job.pushedAt)}
                </TableCell>
                {failed ? (
                  <TableCell className="text-muted-foreground">
                    {formatTimestamp(job.failedAt)}
                  </TableCell>
                ) : (
                  <TableCell className="text-right text-muted-foreground">
                    {job.runtime === null ? (
                      "—"
                    ) : (
                      <Duration seconds={job.runtime} format="precise" />
                    )}
                  </TableCell>
                )}
                {failed ? (
                  <TableCell className="pr-2.5 pl-3 text-right sm:pr-6">
                    <FailedJobActionsMenu jobId={job.id} horizonBaseUrl={horizonBaseUrl} canRetry />
                  </TableCell>
                ) : null}
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </>
  );
}
