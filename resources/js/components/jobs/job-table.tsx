import { Link, router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { useMemo, type Ref } from "react";

import { SortableTableHead } from "@/components/data-table/sortable-table-head";
import {
  controlledSortHeader,
  type ControlledTableSorting,
} from "@/components/data-table/table-sorting";
import { NewEntriesTableRow, TableNoticeRow } from "@/components/data-table/new-entries-alert";
import { RowSelectionHeaderCell } from "@/components/data-table/row-selection-header";
import { TableEmpty } from "@/components/data-table/table-empty";
import { Duration } from "@/components/duration";
import { JobTablePrimaryCell } from "@/components/jobs/job-table-primary-cell";
import { PendingJobActionsMenu } from "@/components/jobs/pending-job-actions";
import {
  CompletedJobsNavigationIcon,
  PendingJobsNavigationIcon,
  SilencedJobsNavigationIcon,
} from "@/components/navigation-icons";
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
import { show as jobShow } from "@/generated/routes/zenith/jobs";
import { useScheduledJobClock } from "@/hooks/use-scheduled-job-clock";
import { formatDuration } from "@/lib/format-duration";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import { pendingJobState, type PendingJobState } from "@/lib/pending-job-state";
import { cn } from "@/lib/utils";
import type { JobListType, JobRow, JobTableSelection } from "@/types/jobs";

const emptyStateIcons = {
  pending: PendingJobsNavigationIcon,
  completed: CompletedJobsNavigationIcon,
  silenced: SilencedJobsNavigationIcon,
} satisfies Record<JobListType, typeof PendingJobsNavigationIcon>;
const pendingStates = {
  ready: {
    label: "Ready",
    variant: "secondary",
    description: "Waiting for a worker to pick it up.",
  },
  reserved: {
    label: "Reserved",
    variant: "processing",
    description: "Reserved by a worker and awaiting processing.",
  },
  delayed: { label: "Delayed", variant: "delayed" },
  released: {
    label: "Released",
    variant: "success",
    description: "Its scheduled release time has passed and it is waiting for a worker.",
  },
} as const;
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

function pendingStateDescription(job: JobRow, state: PendingJobState, now: number) {
  if (state === "delayed") {
    return job.scheduledAt === null
      ? "Scheduled to run later."
      : `Scheduled to run in ${formatDuration(Math.max(0, Math.ceil(job.scheduledAt - now)))}.`;
  }

  return pendingStates[state].description;
}

/**
 * Reserved pending jobs cannot be cancelled, matching the individual pending
 * actions menu which already hides for that state.
 */
function isSelectableJob(job: JobRow, compact: boolean, now: number): boolean {
  return !compact || pendingJobState(job, now) !== "reserved";
}

/** Keep reserved jobs in a stable lead group after client-side sorts of the loaded page. */
function groupReservedPendingJobs(jobs: readonly JobRow[], now: number): JobRow[] {
  const reserved: JobRow[] = [];
  const other: JobRow[] = [];

  for (const job of jobs) {
    if (pendingJobState(job, now) === "reserved") {
      reserved.push(job);
    } else {
      other.push(job);
    }
  }

  return reserved.length === 0 ? [...jobs] : [...reserved, ...other];
}

export function JobTable({
  jobs,
  type,
  horizonBaseUrl,
  available = true,
  message = null,
  notice = null,
  hasNewEntries = false,
  onLoadNewEntries,
  emptyTitle,
  emptyDescription,
  sorting,
  bodyRef,
  showPendingActions = true,
  selection,
}: {
  jobs: readonly JobRow[];
  type: JobListType;
  horizonBaseUrl: string;
  available?: boolean;
  message?: string | null;
  notice?: string | null;
  hasNewEntries?: boolean;
  onLoadNewEntries?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  sorting?: ControlledTableSorting;
  bodyRef?: Ref<HTMLTableSectionElement>;
  /** Individual pending cancel/release actions. Batch tables keep batch-level management only. */
  showPendingActions?: boolean;
  /** Adds a checkbox column for multi-select bulk actions. Omitted, selection is disabled. */
  selection?: JobTableSelection;
}) {
  const now = useScheduledJobClock(jobs);
  const compact = type === "pending";
  const pendingActions = compact && showPendingActions;
  const columnCount = (compact ? (pendingActions ? 4 : 3) : 4) + (selection ? 1 : 0);
  const displayJobs = useMemo(
    () => (compact ? groupReservedPendingJobs(jobs, now) : jobs),
    [compact, jobs, now],
  );
  const hasVisibleJobs = displayJobs.length > 0;
  const selectableIds = useMemo(
    () =>
      selection
        ? displayJobs.filter((job) => isSelectableJob(job, compact, now)).map((job) => job.id)
        : [],
    [selection, displayJobs, compact, now],
  );

  if (!available) {
    const unavailableCopy = {
      pending: {
        title: "Pending jobs couldn’t be loaded",
        fallback: "Horizon could not read retained pending jobs. Refresh the page to try again.",
      },
      completed: {
        title: "Completed jobs couldn’t be loaded",
        fallback: "Horizon could not read retained completed jobs. Refresh the page to try again.",
      },
      silenced: {
        title: "Silenced jobs couldn’t be loaded",
        fallback: "Horizon could not read retained silenced jobs. Refresh the page to try again.",
      },
    } as const satisfies Record<JobListType, { title: string; fallback: string }>;
    const copy = unavailableCopy[type];

    return (
      <div className="box-border w-full min-w-0 p-4">
        <Alert variant="destructive" className="max-w-full">
          <TriangleAlertIcon aria-hidden="true" />
          <AlertTitle>{copy.title}</AlertTitle>
          <AlertDescription>{message ?? copy.fallback}</AlertDescription>
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
          {compact ? (
            <SortableTableHead
              label="State"
              {...controlledSortHeader(sorting, "status")}
              className="w-[130px]"
            />
          ) : null}
          <SortableTableHead
            label="Queued"
            {...controlledSortHeader(sorting, "pushedAt")}
            className={cn("w-[210px]", compact && "text-right")}
          />
          {!compact ? (
            <SortableTableHead
              label="Completed"
              {...controlledSortHeader(sorting, "completedAt")}
              className="w-[210px]"
            />
          ) : null}
          {!compact ? (
            <SortableTableHead
              label="Runtime"
              {...controlledSortHeader(sorting, "runtime")}
              className="w-[110px] text-right"
            />
          ) : null}
          {pendingActions ? (
            <TableHead className="w-[72px] pr-2.5 pl-3 text-right sm:pr-6">Actions</TableHead>
          ) : null}
        </TableRow>
      </TableHeader>
      <TableBody role="presentation">
        {hasNewEntries && onLoadNewEntries ? (
          <NewEntriesTableRow columns={columnCount} onLoad={onLoadNewEntries} />
        ) : null}
        {notice && hasVisibleJobs ? (
          <TableNoticeRow columns={columnCount}>{notice}</TableNoticeRow>
        ) : null}
        {!hasVisibleJobs ? (
          <TableEmpty
            columns={columnCount}
            title={emptyTitle ?? `No ${type} jobs`}
            description={emptyDescription ?? `Horizon is not reporting any ${type} jobs.`}
            icon={emptyStateIcons[type]}
          />
        ) : null}
      </TableBody>
      <TableBody ref={bodyRef}>
        {displayJobs.map((job) => {
          const inspectable = job.inspectable !== false;
          const detailUrl = inspectable
            ? resolveHorizonRoute(jobShow({ type, job: job.id }), horizonBaseUrl).url
            : null;
          const retryOfUrl = job.retryOf
            ? resolveHorizonRoute(failedJobShow(job.retryOf), horizonBaseUrl).url
            : null;
          const state = pendingJobState(job, now);
          const pendingStateDetails = pendingStates[state];

          return (
            <TableRow
              className={detailUrl ? "cursor-pointer" : undefined}
              key={job.id}
              onClick={
                detailUrl
                  ? (event) => {
                      if (isInteractiveTarget(event.target)) {
                        return;
                      }

                      router.visit(detailUrl);
                    }
                  : undefined
              }
              onMouseEnter={detailUrl ? () => router.prefetch(detailUrl) : undefined}
            >
              {selection ? (
                <TableCell className="pr-0" onClick={(event) => event.stopPropagation()}>
                  {isSelectableJob(job, compact, now) ? (
                    <Checkbox
                      aria-label={`Select job ${job.shortName}`}
                      checked={selection.selectedIds.has(job.id)}
                      onCheckedChange={() => selection.onToggle(job.id)}
                    />
                  ) : null}
                </TableCell>
              ) : null}
              <JobTablePrimaryCell
                name={job.shortName}
                fullName={job.name}
                queue={job.queue}
                tags={job.tags}
                href={detailUrl}
                tagLimit={3}
                accessory={job.retryOf ? <Badge variant="retry">Retry</Badge> : undefined}
                details={
                  retryOfUrl ? (
                    <span>
                      Retry of{" "}
                      <Link className="text-foreground hover:underline" href={retryOfUrl} prefetch>
                        {job.retryOf}
                      </Link>
                    </span>
                  ) : undefined
                }
              />
              {compact ? (
                <TableCell>
                  <Tooltip>
                    <TooltipTrigger render={<span className="inline-flex" />}>
                      <Badge className="transition-none" variant={pendingStateDetails.variant}>
                        {pendingStateDetails.label}
                      </Badge>
                    </TooltipTrigger>
                    <TooltipContent>{pendingStateDescription(job, state, now)}</TooltipContent>
                  </Tooltip>
                </TableCell>
              ) : null}
              <TableCell className={cn("text-muted-foreground", compact && "text-right")}>
                {formatTimestamp(job.pushedAt)}
              </TableCell>
              {pendingActions ? (
                <TableCell className="pr-2.5 pl-3 text-right sm:pr-6">
                  {state !== "reserved" ? (
                    <PendingJobActionsMenu
                      jobId={job.id}
                      horizonBaseUrl={horizonBaseUrl}
                      canRelease={state === "delayed"}
                    />
                  ) : null}
                </TableCell>
              ) : null}
              {!compact ? (
                <TableCell className="text-muted-foreground">
                  {formatTimestamp(job.completedAt)}
                </TableCell>
              ) : null}
              {!compact ? (
                <TableCell className="text-right tabular-nums text-muted-foreground">
                  {job.runtime === null ? "—" : <Duration seconds={job.runtime} format="precise" />}
                </TableCell>
              ) : null}
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
