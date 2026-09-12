import { Link, router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { type Ref } from "react";

import { SortableTableHead } from "@/components/data-table/sortable-table-head";
import {
  controlledSortHeader,
  type ControlledTableSorting,
} from "@/components/data-table/table-sorting";
import { NewEntriesTableRow } from "@/components/data-table/new-entries-alert";
import { RowSelectionHeaderCell } from "@/components/data-table/row-selection-header";
import { TableEmpty } from "@/components/data-table/table-empty";
import { Duration } from "@/components/duration";
import { FailedJobActionsMenu } from "@/components/jobs/failed-job-actions";
import { JobTablePrimaryCell } from "@/components/jobs/job-table-primary-cell";
import { FailedJobsNavigationIcon } from "@/components/navigation-icons";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { show as failedJobShow } from "@/generated/routes/zenith/failed-jobs";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import type { JobRow, JobTableSelection } from "@/types/jobs";

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

function upperFirst(value: string | null) {
  return value ? `${value.charAt(0).toUpperCase()}${value.slice(1)}` : "Unknown";
}

function retryStatus(status: string | null) {
  if (status === "pending" || status === "reserved") {
    return { label: "Retry queued", variant: "retry" } as const;
  }

  if (status === "completed") {
    return { label: "Retried · Completed", variant: "success" } as const;
  }

  if (status === "failed") {
    return { label: "Retried", variant: "destructive" } as const;
  }

  return { label: "Retried · Unknown", variant: "warning" } as const;
}

export function FailedJobTable({
  jobs,
  horizonBaseUrl,
  available = true,
  message = null,
  hasNewEntries = false,
  onLoadNewEntries,
  emptyTitle,
  emptyDescription,
  sorting,
  bodyRef,
  selection,
}: {
  jobs: readonly JobRow[];
  horizonBaseUrl: string;
  available?: boolean;
  message?: string | null;
  hasNewEntries?: boolean;
  onLoadNewEntries?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  sorting?: ControlledTableSorting;
  bodyRef?: Ref<HTMLTableSectionElement>;
  /** Adds a checkbox column for multi-select bulk actions. Omitted, selection is disabled. */
  selection?: JobTableSelection;
}) {
  const hasVisibleJobs = jobs.length > 0;
  const columnCount = 4 + (selection ? 1 : 0);
  const selectableIds = selection ? jobs.map((job) => job.id) : [];

  if (!available) {
    return (
      <div className="box-border w-full min-w-0 p-4">
        <Alert variant="destructive" className="max-w-full">
          <TriangleAlertIcon aria-hidden="true" />
          <AlertTitle>Failed jobs couldn’t be loaded</AlertTitle>
          <AlertDescription>
            {message ??
              "Horizon could not read retained failed jobs. Refresh the page to try again."}
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  return (
    <Table>
      <TableHeader className="sticky top-0 z-10">
        <TableRow>
          {selection ? (
            <TableHead className="w-10 pr-0">
              <RowSelectionHeaderCell
                label={selection.label}
                loadedIds={selectableIds}
                selectedCount={selection.selectedIds.size}
                onSelectIds={selection.onSelectIds}
                onClear={selection.onClear}
              />
            </TableHead>
          ) : null}
          <SortableTableHead label="Job" {...controlledSortHeader(sorting, "name")} />
          <SortableTableHead
            label="Runtime"
            {...controlledSortHeader(sorting, "runtime")}
            className="w-[100px] text-right"
          />
          <SortableTableHead
            label="Failed"
            {...controlledSortHeader(sorting, "failedAt")}
            className="w-[190px]"
          />
          <SortableTableHead label="Actions" className="w-[80px] pr-2.5 pl-3 text-right sm:pr-6" />
        </TableRow>
      </TableHeader>
      <TableBody role="presentation">
        {hasNewEntries && onLoadNewEntries ? (
          <NewEntriesTableRow columns={columnCount} onLoad={onLoadNewEntries} />
        ) : null}
        {!hasVisibleJobs ? (
          <TableEmpty
            columns={columnCount}
            title={emptyTitle ?? "No failed jobs"}
            description={emptyDescription ?? "There aren't any failed jobs."}
            icon={FailedJobsNavigationIcon}
          />
        ) : null}
      </TableBody>
      <TableBody ref={bodyRef}>
        {jobs.map((job) => {
          const detailUrl = resolveHorizonRoute(failedJobShow(job.id), horizonBaseUrl).url;
          const retryOfUrl = job.retryOf
            ? resolveHorizonRoute(failedJobShow(job.retryOf), horizonBaseUrl).url
            : null;
          const latestRetry = retryStatus(job.latestRetryStatus);

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
              {selection ? (
                <TableCell className="pr-0" onClick={(event) => event.stopPropagation()}>
                  <Checkbox
                    aria-label={`Select job ${job.shortName}`}
                    checked={selection.selectedIds.has(job.id)}
                    onCheckedChange={() => selection.onToggle(job.id)}
                  />
                </TableCell>
              ) : null}
              <JobTablePrimaryCell
                name={job.shortName}
                fullName={job.name}
                queue={job.queue}
                tags={job.tags}
                href={detailUrl}
                accessory={
                  job.retried ? (
                    <Tooltip>
                      <TooltipTrigger
                        render={
                          <button
                            type="button"
                            className="inline-flex items-center gap-1 text-[11.5px] font-medium text-muted-foreground"
                            title={`Total retries: ${job.retryCount}, Last retry status: ${upperFirst(job.latestRetryStatus)}`}
                          />
                        }
                      >
                        <Badge variant={latestRetry.variant}>{latestRetry.label}</Badge>
                      </TooltipTrigger>
                      <TooltipContent>
                        Total retries: {job.retryCount}, Last retry status:{" "}
                        {upperFirst(job.latestRetryStatus)}
                      </TooltipContent>
                    </Tooltip>
                  ) : undefined
                }
                details={
                  <>
                    <span>Attempts: {job.attempts}</span>
                    {retryOfUrl ? (
                      <span>
                        Retry of{" "}
                        <Link href={retryOfUrl} prefetch>
                          {job.retryOf}
                        </Link>
                      </span>
                    ) : null}
                  </>
                }
              />
              <TableCell className="text-right tabular-nums text-muted-foreground">
                {job.runtime === null ? "—" : <Duration seconds={job.runtime} format="precise" />}
              </TableCell>
              <TableCell className="text-muted-foreground">
                {formatTimestamp(job.failedAt)}
              </TableCell>
              <TableCell className="pr-2.5 pl-3 text-right sm:pr-6">
                <FailedJobActionsMenu
                  jobId={job.id}
                  horizonBaseUrl={horizonBaseUrl}
                  canRetry={job.retryEligible}
                />
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
