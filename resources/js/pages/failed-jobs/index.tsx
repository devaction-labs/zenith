import { Head, InfiniteScroll, router } from "@inertiajs/react";
import { useCallback, useRef } from "react";

import { FailedJobTable } from "@/components/jobs/failed-job-table";
import {
  emptyJobFilterValues,
  JobFilters,
  jobFilterKeys,
  type JobFilterKey,
  type JobFilterOption,
  type JobFilterValues,
} from "@/components/jobs/job-filters";
import { JobsPage } from "@/components/jobs/jobs-page";
import { index as failedJobsIndex } from "@/generated/routes/horizon-new-dawn/failed-jobs";
import { useAutoLoad } from "@/hooks/use-auto-load";
import { useJobFilterCatalogRefresh } from "@/hooks/use-job-filter-catalog-refresh";
import { useJobQueryControls } from "@/hooks/use-job-query-controls";
import { useSortableRows, type SortColumn } from "@/hooks/use-sortable-rows";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { urlWithCurrentQuery } from "@/lib/url-query";
import type { FailedJobsPageProps, JobRow } from "@/types/jobs";

const sortColumns: SortColumn<JobRow>[] = [
  { key: "name", value: (job) => job.name },
  { key: "runtime", value: (job) => job.runtime },
  { key: "failedAt", value: (job) => job.failedAt },
];

function FailedJobsIndex({
  horizon,
  query,
  filters,
  filterCatalog,
  querySignature,
  listRevision,
  jobs,
}: FailedJobsPageProps) {
  const jobItemsRef = useRef<HTMLTableSectionElement>(null);
  const { autoLoad } = useAutoLoadPreference();
  const refreshFilterCatalog = useJobFilterCatalogRefresh(horizon.pollInterval, filterCatalog);
  const queryJobs = useCallback(
    (nextQuery: string, nextFilters: FailedJobsPageProps["filters"]) => {
      const route = resolveHorizonRoute(failedJobsIndex(), horizon.baseUrl);
      const url = urlWithCurrentQuery(
        route.url,
        {
          tag: nextQuery,
          filter_job: nextFilters.job,
          filter_queue: nextFilters.queue,
          filter_connection: nextFilters.connection,
          filter_state: null,
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
    [horizon.baseUrl],
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
    polling: controls.isCommitted,
    listRevision,
    scope: querySignature,
    loadedItemCount: jobs.data.length,
  });
  const sortedJobs = useSortableRows(jobs.data, sortColumns, { persist: true });
  const filterKeys = jobFilterKeys("failed");
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
  let emptyDescription: string | undefined;

  if (hasSearch && hasFilters) {
    emptyDescription = "No failed jobs match this exact tag and filters.";
  } else if (hasSearch) {
    emptyDescription = `No failed jobs match the exact tag “${query}”.`;
  } else if (hasFilters) {
    emptyDescription = "No retained failed jobs match the current filters.";
  }

  const setFilterValue = (filterKey: JobFilterKey, value: string | null) => {
    if (!filterKeys.includes(filterKey)) {
      return;
    }

    controls.setFilterValue(filterKey, value);
  };
  return (
    <>
      <Head title="Jobs" />
      <JobsPage
        activeTab="failed"
        search={controls.search}
        searchLabel="Filter failed jobs by exact tag"
        searchPlaceholder="Enter an exact tag"
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
                ? "Narrow all retained failed jobs with exact server-side filters."
                : (resolvedCatalog.message ?? "Global job filters are currently unavailable.")
            }
          />
        }
      >
        <InfiniteScroll
          key={`${querySignature}:${jobs.available}`}
          data="jobs"
          itemsElement={jobItemsRef}
          onlyNext
          preserveUrl
          buffer={600}
          params={{ onBefore: refreshedJobs.onBeforeNextPage }}
        >
          <FailedJobTable
            jobs={sortedJobs.rows}
            horizonBaseUrl={horizon.baseUrl}
            available={jobs.available}
            message={jobs.message}
            hasNewEntries={refreshedJobs.hasNewEntries}
            onLoadNewEntries={refreshedJobs.loadNewEntries}
            emptyTitle={hasSearch || hasFilters ? "No matching failed jobs" : undefined}
            emptyDescription={emptyDescription}
            sorting={{
              key: sortedJobs.sort?.key ?? null,
              direction: sortedJobs.sort?.direction ?? "asc",
              columns: sortColumns.map((column) => column.key),
              onSort: sortedJobs.toggle,
            }}
            bodyRef={jobItemsRef}
          />
        </InfiniteScroll>
      </JobsPage>
    </>
  );
}

const emptyFilterCatalog = {
  available: false,
  jobs: [],
  queues: [],
  connections: [],
  message: "Preparing exact server-side filters.",
} as const;

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

export default FailedJobsIndex;
