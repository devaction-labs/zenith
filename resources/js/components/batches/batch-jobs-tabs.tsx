import { useMemo } from "react";

import { BatchFailedJobsActions } from "@/components/batches/batch-actions";
import { BatchFailedJobsTable } from "@/components/batches/batch-failed-jobs-table";
import { JobTable } from "@/components/jobs/job-table";
import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { Tabs, TabsContent } from "@/components/ui/tabs";
import { useScheduledJobClock } from "@/hooks/use-scheduled-job-clock";
import { useSortableRows, type SortColumn } from "@/hooks/use-sortable-rows";
import { pendingJobState } from "@/lib/pending-job-state";
import type { BatchJobList, BatchJobTab } from "@/types/batches";
import type { JobRow } from "@/types/jobs";

const tabs: { value: BatchJobTab; label: string; shortLabel: string }[] = [
  { value: "pending", label: "Pending Jobs", shortLabel: "Pending" },
  { value: "completed", label: "Completed Jobs", shortLabel: "Completed" },
  { value: "failed", label: "Failed Jobs", shortLabel: "Failed" },
];

export function BatchJobsTabs({
  value,
  onValueChange,
  jobs,
  horizonBaseUrl,
  batchId,
}: {
  value: BatchJobTab;
  onValueChange: (value: BatchJobTab) => void;
  jobs: Record<BatchJobTab, BatchJobList>;
  horizonBaseUrl: string;
  batchId: string;
}) {
  const availableTabs = tabs
    .filter((tab) => tab.value !== "pending" || jobs.pending.total > 0 || value === "pending")
    .map((tab) => ({
      ...tab,
      count: jobs[tab.value].total,
    }));
  const selectedTab = availableTabs.find((tab) => tab.value === value);
  const selectTab = (next: BatchJobTab | null) => {
    if (!next || next === value) {
      return;
    }

    onValueChange(next);
  };

  return (
    <Tabs
      value={value}
      onValueChange={(next: string) => onValueChange(next as BatchJobTab)}
      className="gap-0"
    >
      <ResponsiveTabsHeader
        value={value}
        items={availableTabs.map((tab) => ({
          value: tab.value,
          label: tab.label,
          desktopLabel: (
            <span>
              <span>{tab.shortLabel}</span> <span>Jobs</span>
            </span>
          ),
          count: tab.count,
          ariaLabel: `${tab.shortLabel} ${tab.count}`,
        }))}
        ariaLabel="Batch jobs"
        onValueChange={selectTab}
        actions={
          value === "failed" ? (
            <BatchFailedJobsActions
              batchId={batchId}
              horizonBaseUrl={horizonBaseUrl}
              disabled={jobs.failed.total === 0}
            />
          ) : undefined
        }
        className="border-b border-separator sm:pr-6"
      />

      <h2 className="sr-only">{selectedTab?.label}</h2>

      <BatchJobTabContent status="pending" list={jobs.pending} horizonBaseUrl={horizonBaseUrl} />
      <BatchJobTabContent
        status="completed"
        list={jobs.completed}
        horizonBaseUrl={horizonBaseUrl}
      />
      <BatchJobTabContent status="failed" list={jobs.failed} horizonBaseUrl={horizonBaseUrl} />
    </Tabs>
  );
}

function BatchJobTabContent({
  status,
  list,
  horizonBaseUrl,
}: {
  status: BatchJobTab;
  list: BatchJobList;
  horizonBaseUrl: string;
}) {
  const emptyTitle =
    list.total > 0 && list.rows.length === 0 && !list.complete
      ? `No retained ${status} jobs`
      : `No ${status} jobs`;
  const emptyDescription =
    list.total > 0 && list.rows.length === 0 && !list.complete
      ? `Horizon has already trimmed these ${status} jobs.`
      : `This batch has no ${status} jobs.`;
  const notice = list.available && !list.complete ? list.message : null;

  return (
    <TabsContent value={status} className="mt-0">
      {status === "failed" ? (
        <BatchFailedJobsTable
          jobs={list.rows}
          horizonBaseUrl={horizonBaseUrl}
          available={list.available}
          message={list.message}
          notice={notice}
          emptyTitle={emptyTitle}
          emptyDescription={emptyDescription}
          sortable={list.complete}
        />
      ) : (
        <BatchRetainedJobsTable
          status={status}
          list={list}
          horizonBaseUrl={horizonBaseUrl}
          notice={notice}
          emptyTitle={emptyTitle}
          emptyDescription={emptyDescription}
        />
      )}
    </TabsContent>
  );
}

function BatchRetainedJobsTable({
  status,
  list,
  horizonBaseUrl,
  notice,
  emptyTitle,
  emptyDescription,
}: {
  status: Exclude<BatchJobTab, "failed">;
  list: BatchJobList;
  horizonBaseUrl: string;
  notice: string | null;
  emptyTitle: string;
  emptyDescription: string;
}) {
  const now = useScheduledJobClock(list.rows);
  const columns = useMemo<SortColumn<JobRow>[]>(
    () => [
      { key: "name", value: (job) => job.name },
      ...(status === "pending"
        ? [{ key: "status", value: (job: JobRow) => pendingJobState(job, now) }]
        : []),
      { key: "pushedAt", value: (job) => job.pushedAt },
      ...(status === "completed"
        ? [
            { key: "completedAt", value: (job: JobRow) => job.completedAt },
            { key: "runtime", value: (job: JobRow) => job.runtime },
          ]
        : []),
    ],
    [now, status],
  );
  const sorted = useSortableRows(list.rows, columns, {
    persist: true,
    prefix: `batch_${status}`,
    defaultSort: status === "completed" ? { key: "completedAt", direction: "desc" } : undefined,
  });

  return (
    <JobTable
      jobs={list.complete ? sorted.rows : list.rows}
      type={status}
      horizonBaseUrl={horizonBaseUrl}
      available={list.available}
      message={list.message}
      notice={notice}
      emptyTitle={emptyTitle}
      emptyDescription={emptyDescription}
      showPendingActions={false}
      sorting={
        list.complete
          ? {
              key: sorted.sort?.key ?? null,
              direction: sorted.sort?.direction ?? "asc",
              columns: columns.map((column) => column.key),
              onSort: sorted.toggle,
            }
          : undefined
      }
    />
  );
}
