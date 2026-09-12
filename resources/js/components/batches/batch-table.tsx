import { Link, router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";
import { type Ref } from "react";

import { BatchPendingJobsActions } from "@/components/batches/batch-actions";
import { ProgressRing } from "@/components/batches/progress-ring";
import { NewEntriesTableRow } from "@/components/data-table/new-entries-alert";
import { SortableTableHead } from "@/components/data-table/sortable-table-head";
import {
  controlledSortHeader,
  type ControlledTableSorting,
} from "@/components/data-table/table-sorting";
import { TableEmpty } from "@/components/data-table/table-empty";
import { BatchesNavigationIcon } from "@/components/navigation-icons";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { show as batchShow } from "@/generated/routes/zenith/batches";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { isInteractiveTarget } from "@/lib/interactive-target";
import type { BatchRow } from "@/types/batches";

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

export function BatchTable({
  batches,
  horizonBaseUrl,
  available = true,
  message = null,
  hasNewEntries = false,
  onLoadNewEntries,
  emptyTitle,
  emptyDescription,
  showBatchActions = false,
  sorting,
  bodyRef,
}: {
  batches: readonly BatchRow[];
  horizonBaseUrl: string;
  available?: boolean;
  message?: string | null;
  hasNewEntries?: boolean;
  onLoadNewEntries?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  showBatchActions?: boolean;
  sorting?: ControlledTableSorting;
  bodyRef?: Ref<HTMLTableSectionElement>;
}) {
  const hasVisibleBatches = batches.length > 0;

  if (!available) {
    return (
      <Alert variant="destructive" className="m-4">
        <TriangleAlertIcon aria-hidden="true" />
        <AlertTitle>Batches unavailable</AlertTitle>
        <AlertDescription>{message ?? "Batches are currently unavailable."}</AlertDescription>
      </Alert>
    );
  }

  return (
    <>
      <Table className="min-w-[960px] min-[1140px]:min-w-0">
        <TableHeader className="sticky top-0 z-10">
          <TableRow>
            <SortableTableHead label="Batch" {...controlledSortHeader(sorting, "name")} />
            <SortableTableHead
              label="Total"
              {...controlledSortHeader(sorting, "totalJobs")}
              className="w-px text-right"
            />
            <SortableTableHead
              label="Failed"
              {...controlledSortHeader(sorting, "failedJobs")}
              className="w-[90px] text-right"
            />
            <SortableTableHead
              label="Progress"
              {...controlledSortHeader(sorting, "progress")}
              className="w-[130px] px-4"
            />
            <SortableTableHead
              label="Created At"
              {...controlledSortHeader(sorting, "createdAt")}
              className="w-[190px]"
            />
            {showBatchActions ? (
              <TableHead className="w-14 pr-2.5 pl-3 text-right sm:pr-6">Actions</TableHead>
            ) : null}
          </TableRow>
        </TableHeader>
        <TableBody role="presentation">
          {hasNewEntries && onLoadNewEntries ? (
            <NewEntriesTableRow columns={showBatchActions ? 6 : 5} onLoad={onLoadNewEntries} />
          ) : null}
          {!hasVisibleBatches ? (
            <TableEmpty
              columns={showBatchActions ? 6 : 5}
              title={emptyTitle ?? "No batches"}
              description={emptyDescription ?? "There aren't any batches."}
              icon={BatchesNavigationIcon}
            />
          ) : null}
        </TableBody>
        <TableBody ref={bodyRef}>
          {batches.map((batch) => {
            const detailUrl = resolveHorizonRoute(batchShow(batch.id), horizonBaseUrl).url;

            return (
              <TableRow
                className="cursor-pointer"
                key={batch.id}
                onClick={(event) => {
                  if (isInteractiveTarget(event.target)) {
                    return;
                  }

                  router.visit(detailUrl);
                }}
                onMouseEnter={() => router.prefetch(detailUrl)}
              >
                <TableCell className="max-w-0">
                  <Link
                    className="block max-w-full truncate font-normal text-foreground"
                    data-batch-id={batch.id}
                    href={detailUrl}
                    prefetch
                    title={batch.displayName}
                  >
                    {batch.displayName}
                  </Link>
                </TableCell>
                <TableCell className="text-right tabular-nums text-muted-foreground">
                  {numberFormatter.format(batch.totalJobs)}
                </TableCell>
                <TableCell className="text-right tabular-nums text-muted-foreground">
                  {numberFormatter.format(batch.failedJobs)}
                </TableCell>
                <TableCell className="px-4 text-muted-foreground">
                  <ProgressRing
                    value={batch.progress}
                    cancelled={batch.status === "cancelled"}
                    pendingJobs={batch.pendingJobs}
                    failedJobs={batch.failedJobs}
                  />
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {dateFormatter.format(batch.createdAt * 1000)}
                </TableCell>
                {showBatchActions ? (
                  <TableCell className="w-14 pr-2.5 pl-3 text-right sm:pr-6">
                    <BatchPendingJobsActions
                      batchId={batch.id}
                      horizonBaseUrl={horizonBaseUrl}
                      canRetry={batch.failedJobs > 0}
                      canCancel={
                        batch.status !== "cancelled" &&
                        batch.status !== "finished" &&
                        Math.max(0, batch.pendingJobs - batch.failedJobs) > 0
                      }
                      ariaLabel={`Batch actions for ${batch.displayName}`}
                    />
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
