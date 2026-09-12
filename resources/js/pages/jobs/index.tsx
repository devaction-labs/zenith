import { Head, InfiniteScroll, router } from "@inertiajs/react";
import { useCallback, useEffect, useRef } from "react";

import { SelectionToolbar } from "@/components/data-table/selection-toolbar";
import {
  emptyJobFilterValues,
  JobFilters,
  jobFilterKeys,
  type JobFilterKey,
  type JobFilterOption,
  type JobFilterValues,
} from "@/components/jobs/job-filters";
import { JobTable } from "@/components/jobs/job-table";
import { JobsPage } from "@/components/jobs/jobs-page";
import { CancelSelectedPendingJobsButton } from "@/components/jobs/selected-jobs-actions";
import { index as jobsIndex } from "@/generated/routes/zenith/jobs";
import { useAutoLoad } from "@/hooks/use-auto-load";
import { useJobFilterCatalogRefresh } from "@/hooks/use-job-filter-catalog-refresh";
import { useJobQueryControls } from "@/hooks/use-job-query-controls";
import { useRowSelection } from "@/hooks/use-row-selection";
import { useSortableRows, type SortColumn } from "@/hooks/use-sortable-rows";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { urlWithCurrentQuery } from "@/lib/url-query";
import type { JobRow, JobsPageProps } from "@/types/jobs";

const sortColumns = {
  pending: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
  ],
  completed: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
    { key: "completedAt", value: (job) => job.completedAt },
    { key: "runtime", value: (job) => job.runtime },
  ],
  silenced: [
    { key: "name", value: (job) => job.name },
    { key: "pushedAt", value: (job) => job.pushedAt },
    { key: "completedAt", value: (job) => job.completedAt },
    { key: "runtime", value: (job) => job.runtime },
  ],
} satisfies Record<JobsPageProps["type"], readonly SortColumn<JobRow>[]>;
const completedAtDescending = { key: "completedAt", direction: "desc" } as const;

function JobsIndex(props: JobsPageProps) {
  return (
    <>
      <Head title="Jobs" />
      <JobsContent key={props.type} {...props} />
    </>
  );
}

function JobsContent({
  horizon,
  type,
  query,
  filters,
  filterCatalog,
  querySignature,
  listRevision,
  jobs,
}: JobsPageProps) {
  const jobItemsRef = useRef<HTMLTableSectionElement>(null);
  const { autoLoad } = useAutoLoadPreference();
  const refreshFilterCatalog = useJobFilterCatalogRefresh(horizon.pollInterval, filterCatalog);
  const queryJobs = useCallback(
    (nextQuery: string, nextFilters: JobsPageProps["filters"]) => {
      const route = resolveHorizonRoute(jobsIndex(type), horizon.baseUrl);
      const url = urlWithCurrentQuery(
        route.url,
        {
          query: nextQuery,
          filter_job: nextFilters.job,
          filter_queue: nextFilters.queue,
          filter_connection: nextFilters.connection,
          filter_state: type === "pending" ? nextFilters.state : null,
          filter_tag: nextFilters.tag,
        },
        ["starting_at"],
      );

      router.get(
        url,
        {},
        {
          only: jobQueryProps,
          preserveScroll: true,
          preserveState: true,
          replace: true,
          reset: ["jobs"],
        },
      );
    },
    [horizon.baseUrl, type],
  );
  const controls = useJobQueryControls({
    query,
    filters,
    onSubmit: queryJobs,
  });
  const refreshedJobs = useAutoLoad({
    enabled: autoLoad,
    prop: "jobs",
    interval: horizon.pollInterval,
    listRevision,
    additionalProps: type === "pending" ? pendingRefreshProps : undefined,
    scope: querySignature,
    polling: controls.isCommitted,
    loadedItemCount: jobs.data.length,
  });
  const columns = sortColumns[type];
  const sortedJobs = useSortableRows(jobs.data, columns, {
    persist: true,
    defaultSort: type === "pending" ? undefined : completedAtDescending,
  });
  const selection = useRowSelection();
  const clearSelection = selection.clear;

  useEffect(() => {
    clearSelection();
  }, [querySignature, clearSelection]);
  const filterKeys = jobFilterKeys(type);
  const filterValues: JobFilterValues = {
    ...emptyJobFilterValues,
    ...controls.filters,
  };
  const hasSearch = query !== "";
  const hasFilters = filterKeys.some((key) => filterValues[key] !== null);
  const resolvedCatalog = filterCatalog ?? emptyFilterCatalog;
  const filterOptions = {
    job: catalogOptions(resolvedCatalog.jobs, filterValues.job, jobLabel(filterValues.job)),
    queue: catalogOptions(
      resolvedCatalog.queues.map((value) => ({ value, label: value })),
      filterValues.queue,
      filterValues.queue,
    ),
    connection: catalogOptions(
      resolvedCatalog.connections.map((value) => ({ value, label: value })),
      filterValues.connection,
      filterValues.connection,
    ),
  };
  const setFilterValue = (filterKey: JobFilterKey, value: string | null) => {
    if (!filterKeys.includes(filterKey)) {
      return;
    }

    controls.setFilterValue(filterKey, value);
  };
  return (
    <JobsPage
      activeTab={type}
      search={controls.search}
      searchLabel={`Search ${type} jobs by class or ID`}
      searchPlaceholder="Search by job class or exact ID"
      onSearchChange={controls.setSearch}
      filters={
        <JobFilters
          filterKeys={filterKeys}
          options={filterOptions}
          values={filterValues}
          onIntent={refreshFilterCatalog}
          onFilterChange={setFilterValue}
          onClearFilters={controls.clearFilters}
          description={
            resolvedCatalog.available
              ? (resolvedCatalog.message ??
                `Narrow all retained ${type} jobs with exact server-side filters.`)
              : (resolvedCatalog.message ?? "Global job filters are currently unavailable.")
          }
        />
      }
    >
      <SelectionToolbar count={selection.selectedCount} noun="job" onClear={selection.clear}>
        {type === "pending" ? (
          <CancelSelectedPendingJobsButton
            horizonBaseUrl={horizon.baseUrl}
            ids={Array.from(selection.selectedIds)}
            onDone={selection.clear}
          />
        ) : null}
      </SelectionToolbar>
      <InfiniteScroll
        key={`${type}:${querySignature}:${jobs.available}`}
        data="jobs"
        itemsElement={jobItemsRef}
        onlyNext
        preserveUrl
        buffer={600}
        params={{ onBefore: refreshedJobs.onBeforeNextPage }}
      >
        <JobTable
          jobs={sortedJobs.rows}
          type={type}
          horizonBaseUrl={horizon.baseUrl}
          available={jobs.available}
          message={jobs.message}
          hasNewEntries={refreshedJobs.hasNewEntries}
          onLoadNewEntries={refreshedJobs.loadNewEntries}
          emptyTitle={hasSearch || hasFilters ? `No matching ${type} jobs` : undefined}
          emptyDescription={
            hasSearch && hasFilters
              ? `No retained ${type} jobs match this search and filters.`
              : hasSearch
                ? `No retained ${type} jobs match “${query}”.`
                : hasFilters
                  ? `No retained ${type} jobs match the current filters.`
                  : undefined
          }
          sorting={{
            key: sortedJobs.sort?.key ?? null,
            direction: sortedJobs.sort?.direction ?? "asc",
            columns: columns.map((column) => column.key),
            onSort: sortedJobs.toggle,
          }}
          bodyRef={jobItemsRef}
          selection={{
            label: `${type} jobs`,
            selectedIds: selection.selectedIds,
            onToggle: selection.toggle,
            onSelectIds: selection.selectIds,
            onClear: selection.clear,
          }}
        />
      </InfiniteScroll>
    </JobsPage>
  );
}

const emptyFilterCatalog = {
  available: false,
  jobs: [],
  queues: [],
  connections: [],
  message: "Preparing exact server-side filters.",
} as const;

const pendingRefreshProps = ["pendingCounts"];
const jobQueryProps = ["query", "filters", "querySignature", "listRevision", "jobs", "horizon"];

function catalogOptions(
  options: readonly JobFilterOption[],
  activeValue: string | null,
  activeLabel: string | null,
): JobFilterOption[] {
  const resolved =
    activeValue === null
      ? options
      : [...options, { value: activeValue, label: activeLabel ?? activeValue }];

  return Array.from(new Map(resolved.map((option) => [option.value, option])).values());
}

function jobLabel(job: string | null): string | null {
  return job?.split("\\").at(-1) ?? null;
}

export default JobsIndex;
