import { Link } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { useState } from "react";

import { SortableTableHead } from "@/components/data-table/sortable-table-head";
import { Duration } from "@/components/duration";
import { DashboardNavigationIcon } from "@/components/navigation-icons";
import { QueueActionsMenu, QueuePauseBadge } from "@/components/queues/queue-actions-menu";
import {
  QueueWaitThresholdBadge,
  QueueWaitThresholdCell,
  queueWaitThresholdSortRank,
} from "@/components/queues/queue-wait-threshold";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
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
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { show as queueShow } from "@/generated/routes/horizon-new-dawn/queues";
import {
  sortRows,
  useSortableRows,
  type SortColumn,
  type SortState,
} from "@/hooks/use-sortable-rows";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import { prefetchQueueDetail, visitQueueDetail } from "@/lib/queue-detail-navigation";
import { cn } from "@/lib/utils";
import type { DashboardWorkload, WorkloadItem, WorkloadSplitQueue } from "@/types/dashboard";

const numberFormatter = new Intl.NumberFormat();
const columns: SortColumn<WorkloadItem>[] = [
  { key: "name", value: (item) => item.name },
  {
    key: "waitThreshold",
    value: (item) => queueWaitThresholdSortRank[item.waitThreshold?.status ?? "disabled"],
  },
  { key: "length", value: (item) => item.length },
  { key: "processes", value: (item) => item.processes },
  {
    key: "throughput",
    value: (item) => item.throughput ?? Number.NEGATIVE_INFINITY,
  },
  { key: "wait", value: (item) => item.wait },
];
const splitQueueColumns: SortColumn<WorkloadSplitQueue>[] = [
  { key: "name", value: (queue) => queue.name },
  {
    key: "waitThreshold",
    value: (queue) => queueWaitThresholdSortRank[queue.waitThreshold?.status ?? "disabled"],
  },
  { key: "length", value: (queue) => queue.length },
  { key: "processes", value: () => null },
  {
    key: "throughput",
    value: (queue) => queue.throughput ?? Number.NEGATIVE_INFINITY,
  },
  { key: "wait", value: (queue) => queue.wait },
];

function directionFor(key: string, sort: { key: string; direction: "asc" | "desc" } | null) {
  return sort?.key === key ? sort.direction : undefined;
}

function poolQueues(name: string): string[] {
  return name
    .split(",")
    .map((queue) => queue.trim())
    .filter(Boolean);
}

/** 1–3 queues: full list. 4+: first two + remaining count. */
function formatPoolLabel(name: string): { label: string; fullList: string | null } {
  const queues = poolQueues(name);

  if (queues.length <= 1) {
    return { label: name, fullList: null };
  }

  const fullList = queues.join(", ");

  if (queues.length <= 3) {
    return { label: fullList, fullList: null };
  }

  return {
    label: `${queues[0]}, ${queues[1]}, +${queues.length - 2}`,
    fullList,
  };
}

function ProcessesCell({
  processes,
  processesShared,
}: {
  processes: number;
  processesShared: boolean;
}) {
  const count = numberFormatter.format(processes);

  if (!processesShared) {
    return <span className="tabular-nums">{count}</span>;
  }

  return (
    <Tooltip>
      <TooltipTrigger render={<span className="cursor-help tabular-nums">{count}</span>} />
      <TooltipContent side="top">Workers are shared by the queues in this pool.</TooltipContent>
    </Tooltip>
  );
}

/** Zero ready jobs and zero wait is empty work, not a timed wait — show an em dash. */
function WorkloadWaitCell({ readyJobs, wait }: { readyJobs: number; wait: number }) {
  if (readyJobs === 0 && wait === 0) {
    return <span className="text-muted-foreground">—</span>;
  }

  return <Duration seconds={wait} />;
}

/** Count exceeded child queues for a shared-pool parent badge (never "0 exceeded"). */
function sharedPoolExceededCount(item: WorkloadItem): number {
  return (item.splitQueues ?? []).filter((queue) => queue.waitThreshold?.status === "exceeded")
    .length;
}

export function WorkloadTable({
  workload,
  horizonBaseUrl,
  queuePausing = true,
  timedQueuePausing = true,
}: {
  workload: DashboardWorkload;
  horizonBaseUrl: string;
  queuePausing?: boolean;
  timedQueuePausing?: boolean;
}) {
  const sorted = useSortableRows(workload.items, columns, { persist: true, prefix: "workload" });

  if (!workload.available) {
    return (
      <Alert variant="destructive" className="m-4">
        <TriangleAlertIcon aria-hidden="true" />
        <AlertTitle>Workload unavailable</AlertTitle>
        <AlertDescription>
          {workload.message ?? "Horizon workload is currently unavailable."}
        </AlertDescription>
      </Alert>
    );
  }

  if (workload.items.length === 0) {
    return (
      <Empty className="min-h-48">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <DashboardNavigationIcon aria-hidden="true" />
          </EmptyMedia>
          <EmptyTitle>All queues are clear</EmptyTitle>
          <EmptyDescription>Horizon has no queued workload right now.</EmptyDescription>
        </EmptyHeader>
      </Empty>
    );
  }

  return (
    <Table>
      <TableHeader className="sticky top-0 z-10">
        <TableRow>
          <SortableTableHead
            label="Queue"
            columnKey="name"
            direction={directionFor("name", sorted.sort)}
            onSort={sorted.toggle}
            className="w-[28%]"
          />
          <SortableTableHead
            label="Wait threshold"
            columnKey="waitThreshold"
            direction={directionFor("waitThreshold", sorted.sort)}
            onSort={sorted.toggle}
            className="w-[150px]"
          />
          <SortableTableHead
            label="Ready Jobs"
            columnKey="length"
            direction={directionFor("length", sorted.sort)}
            onSort={sorted.toggle}
            className="text-right"
          />
          <SortableTableHead
            label="Processes"
            columnKey="processes"
            direction={directionFor("processes", sorted.sort)}
            onSort={sorted.toggle}
            className="w-[120px] text-right"
          />
          <SortableTableHead
            label="Throughput"
            columnKey="throughput"
            direction={directionFor("throughput", sorted.sort)}
            onSort={sorted.toggle}
            className="w-[120px] text-right"
          />
          <SortableTableHead
            label="Wait"
            columnKey="wait"
            direction={directionFor("wait", sorted.sort)}
            onSort={sorted.toggle}
            className="w-[130px] text-right"
          />
          <TableHead className="w-12 pr-2.5 pl-3 text-right sm:pr-6">
            <span className="sr-only">Actions</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {sorted.rows.map((item) => (
          <WorkloadRows
            item={item}
            sort={sorted.sort}
            horizonBaseUrl={horizonBaseUrl}
            queuePausing={queuePausing}
            timedQueuePausing={timedQueuePausing}
            key={`${item.connection}:${item.name}`}
          />
        ))}
      </TableBody>
    </Table>
  );
}

function WorkloadRows({
  item,
  sort,
  horizonBaseUrl,
  queuePausing,
  timedQueuePausing,
}: {
  item: WorkloadItem;
  sort: SortState | null;
  horizonBaseUrl: string;
  queuePausing: boolean;
  timedQueuePausing: boolean;
}) {
  const [expanded, setExpanded] = useState(false);
  const grouped = item.splitQueues !== null && item.splitQueues.length > 0;
  const splitQueues = grouped ? sortRows(item.splitQueues!, splitQueueColumns, sort) : [];
  const { label, fullList } = formatPoolLabel(item.name);
  const exceededChildren = grouped ? sharedPoolExceededCount(item) : 0;
  const detailUrl = grouped
    ? null
    : resolveHorizonRoute(queueShow(encodeURIComponent(item.name)), horizonBaseUrl).url;

  return (
    <>
      <TableRow
        className={cn(grouped && "cursor-pointer")}
        data-state={grouped && expanded ? "open" : undefined}
        onMouseEnter={detailUrl ? () => prefetchQueueDetail(detailUrl) : undefined}
        onClick={
          grouped
            ? (event) => {
                if (isInteractiveTarget(event.target)) {
                  return;
                }

                setExpanded((open) => !open);
              }
            : detailUrl
              ? (event) => {
                  if (isInteractiveTarget(event.target)) {
                    return;
                  }

                  visitQueueDetail(detailUrl);
                }
              : undefined
        }
      >
        <TableCell className={cn(grouped && "font-semibold")}>
          {grouped ? (
            <button
              type="button"
              className="inline-flex max-w-full items-center text-left text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-expanded={expanded}
              aria-label={
                expanded
                  ? `Collapse shared pool ${fullList ?? label}`
                  : `Expand shared pool ${fullList ?? label}`
              }
              onClick={(event) => {
                event.stopPropagation();
                setExpanded((open) => !open);
              }}
            >
              {fullList ? (
                <Tooltip>
                  <TooltipTrigger
                    render={<span className="min-w-0 truncate cursor-help">{label}</span>}
                  />
                  <TooltipContent side="top">{fullList}</TooltipContent>
                </Tooltip>
              ) : (
                <span className="min-w-0 truncate">{label}</span>
              )}
            </button>
          ) : (
            <Link
              className="inline-flex items-center gap-2 text-foreground"
              href={detailUrl!}
              prefetch
            >
              {item.name}
              <QueuePauseBadge paused={item.paused} pausedUntil={item.pausedUntil} />
            </Link>
          )}
        </TableCell>
        <TableCell tone="secondary">
          {exceededChildren > 0 ? (
            <QueueWaitThresholdBadge
              status="exceeded"
              label={
                exceededChildren === item.splitQueues!.length
                  ? "All exceeded"
                  : `${exceededChildren} exceeded`
              }
            />
          ) : (
            <QueueWaitThresholdCell waitThreshold={item.waitThreshold} />
          )}
        </TableCell>
        <TableCell
          tone="secondary"
          className={cn("text-right tabular-nums", grouped && "font-semibold")}
        >
          {numberFormatter.format(item.length)}
        </TableCell>
        <TableCell tone="secondary" className={cn("text-right", grouped && "font-semibold")}>
          <ProcessesCell processes={item.processes} processesShared={item.processesShared} />
        </TableCell>
        <TableCell
          tone="secondary"
          className={cn("text-right tabular-nums", grouped && "font-semibold")}
        >
          {item.throughput === null ? "—" : numberFormatter.format(item.throughput)}
        </TableCell>
        <TableCell
          tone="secondary"
          className={cn("text-right tabular-nums", grouped && "font-semibold")}
        >
          <WorkloadWaitCell readyJobs={item.length} wait={item.wait} />
        </TableCell>
        <TableCell tone="secondary" className="pr-2.5 pl-3 text-right sm:pr-6">
          {!grouped ? (
            <QueueActionsMenu
              connection={item.connection}
              queue={item.name}
              paused={item.paused}
              pausedUntil={item.pausedUntil}
              pendingJobs={item.length}
              horizonBaseUrl={horizonBaseUrl}
              queuePausing={queuePausing}
              timedQueuePausing={timedQueuePausing}
            />
          ) : null}
        </TableCell>
      </TableRow>
      {grouped && expanded
        ? splitQueues.map((queue) => (
            <WorkloadSplitRow
              key={`${item.name}:${queue.name}`}
              connection={item.connection}
              queue={queue}
              horizonBaseUrl={horizonBaseUrl}
              queuePausing={queuePausing}
              timedQueuePausing={timedQueuePausing}
            />
          ))
        : null}
    </>
  );
}

function WorkloadSplitRow({
  connection,
  queue,
  horizonBaseUrl,
  queuePausing,
  timedQueuePausing,
}: {
  connection: string;
  queue: WorkloadSplitQueue;
  horizonBaseUrl: string;
  queuePausing: boolean;
  timedQueuePausing: boolean;
}) {
  const detailUrl = resolveHorizonRoute(
    queueShow(encodeURIComponent(queue.name)),
    horizonBaseUrl,
  ).url;

  return (
    <TableRow
      onMouseEnter={() => prefetchQueueDetail(detailUrl)}
      onClick={(event) => {
        if (isInteractiveTarget(event.target)) {
          return;
        }

        visitQueueDetail(detailUrl);
      }}
    >
      <TableCell tone="secondary">
        <Link
          className="inline-flex min-w-0 items-center gap-1.5 text-foreground"
          href={detailUrl}
          prefetch
        >
          <svg
            aria-hidden="true"
            viewBox="0 0 16 16"
            fill="none"
            className="size-3.5 shrink-0 text-muted-foreground/70"
          >
            <path
              d="M4 2v6a3 3 0 0 0 3 3h3.5"
              stroke="currentColor"
              strokeWidth="1.25"
              strokeLinecap="round"
            />
          </svg>
          <span className="min-w-0 truncate">{queue.name}</span>
          <QueuePauseBadge paused={queue.paused} pausedUntil={queue.pausedUntil} />
        </Link>
      </TableCell>
      <TableCell tone="secondary">
        <QueueWaitThresholdCell waitThreshold={queue.waitThreshold} />
      </TableCell>
      <TableCell tone="secondary" className="text-right tabular-nums">
        {numberFormatter.format(queue.length)}
      </TableCell>
      <TableCell tone="secondary" className="text-right text-muted-foreground">
        —
      </TableCell>
      <TableCell tone="secondary" className="text-right tabular-nums">
        {queue.throughput === null ? "—" : numberFormatter.format(queue.throughput)}
      </TableCell>
      <TableCell tone="secondary" className="text-right tabular-nums">
        <WorkloadWaitCell readyJobs={queue.length} wait={queue.wait} />
      </TableCell>
      <TableCell tone="secondary" className="pr-2.5 pl-3 text-right sm:pr-6">
        <QueueActionsMenu
          connection={connection}
          queue={queue.name}
          paused={queue.paused}
          pausedUntil={queue.pausedUntil}
          pendingJobs={queue.length}
          horizonBaseUrl={horizonBaseUrl}
          queuePausing={queuePausing}
          timedQueuePausing={timedQueuePausing}
        />
      </TableCell>
    </TableRow>
  );
}
