import { Head, InfiniteScroll, router } from "@inertiajs/react";
import { DatabaseIcon, EllipsisIcon, SearchIcon, TriangleAlertIcon, XIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { BatchTable } from "@/components/batches/batch-table";
import { BatchFilters } from "@/components/batches/batch-filters";
import { BatchesActions } from "@/components/batches/batches-actions";
import { CodeBlock } from "@/components/code-block";
import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { ListPageHeader } from "@/components/shell/list-page-header";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import { Field, FieldLabel } from "@/components/ui/field";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
} from "@/components/ui/input-group";
import { Tabs, TabsContent } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { index as batchesIndex } from "@/generated/routes/zenith/batches";
import { useAutoLoad } from "@/hooks/use-auto-load";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { formatCount } from "@/lib/format-count";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { urlWithCurrentQuery } from "@/lib/url-query";
import type {
  BatchClearCounts,
  BatchFilterCatalog,
  BatchFilterValues,
  BatchQueryCapability,
  BatchQueryFilters,
  BatchSort,
  BatchStatusCounts,
  BatchesPageProps,
  BatchStatus,
} from "@/types/batches";

const batchTabs: Array<{ label: string; value: BatchStatus }> = [
  { label: "Pending", value: "pending" },
  { label: "Complete", value: "finished" },
  { label: "Incomplete", value: "failures" },
  { label: "Cancelled", value: "cancelled" },
];
const emptyBatchStatusCopy: Record<BatchStatus, { title: string; description: string }> = {
  pending: {
    title: "No pending batches",
    description: "Horizon is not reporting any pending batches.",
  },
  finished: {
    title: "No complete batches",
    description: "Horizon is not reporting any complete batches.",
  },
  failures: {
    title: "No incomplete batches",
    description: "Horizon is not reporting any incomplete batches.",
  },
  cancelled: {
    title: "No cancelled batches",
    description: "Horizon is not reporting any cancelled batches.",
  },
};
const batchSortColumns: readonly BatchSort[] = [
  "name",
  "totalJobs",
  "failedJobs",
  "progress",
  "createdAt",
];
const emptyBatchFilters: BatchQueryFilters = {
  queue: null,
  connection: null,
  created: null,
  status: "all",
  sort: "createdAt",
  direction: "desc",
};
const emptyBatchStatusCounts: BatchStatusCounts = {
  all: 0,
  pending: 0,
  finished: 0,
  failures: 0,
  cancelled: 0,
};
const unavailableBatchQuery: BatchQueryCapability = {
  supported: false,
  message: "Exact retained batch filters and sorting are unavailable.",
  attributionSupported: false,
  attributionMessage: "Batch queue and connection attribution is unavailable.",
};
const emptyBatchClearCounts: BatchClearCounts = {
  incomplete: 0,
  complete: 0,
  finished: 0,
  cancelled: 0,
  available: false,
  completeScan: false,
  message: null,
};
const emptyBatchFilterCatalog: BatchFilterCatalog = {
  available: false,
  complete: false,
  message: null,
  queues: [],
  connections: [],
};
const batchSetupCommands = "php artisan make:queue-batches-table\nphp artisan migrate";

function BatchesStorageUnavailable({ message }: { message: string | null }) {
  const showSetupCommands = message === null || message.includes("make:queue-batches-table");

  return (
    <>
      <Head title="Batches" />
      <Card>
        <ListPageHeader title="Batches" separated={false} />
        <CardContent className="p-0">
          <Empty className="min-h-64 border-0 py-16">
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <DatabaseIcon aria-hidden="true" />
              </EmptyMedia>
              <EmptyTitle>Job batching is not configured</EmptyTitle>
              <EmptyDescription>
                {showSetupCommands
                  ? "Run the batches migration to enable job batching."
                  : (message ?? "Batches are currently unavailable.")}
              </EmptyDescription>
            </EmptyHeader>
            {showSetupCommands ? (
              <EmptyContent className="items-stretch">
                <CodeBlock
                  className="w-full min-w-0 rounded-lg text-left"
                  copyLabel="commands"
                  copyValue={batchSetupCommands}
                >
                  <code>{batchSetupCommands}</code>
                </CodeBlock>
              </EmptyContent>
            ) : null}
          </Empty>
        </CardContent>
      </Card>
    </>
  );
}

function BatchesIndex(props: BatchesPageProps) {
  if (props.batchesAvailable === false) {
    return <BatchesStorageUnavailable message={props.batches.message} />;
  }

  return <BatchesIndexContent {...props} />;
}

function BatchesIndexContent({
  horizon,
  query,
  filters = emptyBatchFilters,
  listRevision,
  batches,
  batchClearCounts = emptyBatchClearCounts,
  batchFilterCatalog = emptyBatchFilterCatalog,
  batchStatusCounts = emptyBatchStatusCounts,
  batchQueryCapability = unavailableBatchQuery,
}: BatchesPageProps) {
  const [search, setSearch] = useState(query);
  const batchItemsRef = useRef<HTMLTableSectionElement>(null);
  const { autoLoad } = useAutoLoadPreference();
  const filterScope = [
    query,
    filters.queue ?? "",
    filters.connection ?? "",
    filters.created ?? "",
    filters.status,
    filters.sort,
    filters.direction,
  ].join(":");
  const refreshedBatches = useAutoLoad({
    enabled: autoLoad,
    prop: "batches",
    interval: horizon.pollInterval,
    polling: search.trim() === query,
    cursor: "before_id",
    listRevision,
    additionalProps: ["batchStatusCounts"],
    scope: filterScope,
    loadedItemCount: batches.data.length,
  });
  const queueOptions = batchFilterOptions(batchFilterCatalog.queues, filters.queue);
  const connectionOptions = batchFilterOptions(batchFilterCatalog.connections, filters.connection);

  useEffect(() => {
    const value = search.trim();

    if (value === query) {
      return;
    }

    const timer = window.setTimeout(() => {
      const route = resolveHorizonRoute(batchesIndex(), horizon.baseUrl);
      const url = urlWithCurrentQuery(route.url, { query: value }, ["before_id"]);

      router.get(
        url,
        {},
        {
          only: [
            "query",
            "filters",
            "batchStatusCounts",
            "batchQueryCapability",
            "listRevision",
            "batches",
            "horizon",
          ],
          preserveScroll: true,
          preserveState: true,
          replace: true,
          reset: ["batches"],
        },
      );
    }, 500);

    return () => window.clearTimeout(timer);
  }, [horizon.baseUrl, query, search]);

  const updateQuery = (parameters: Record<string, string | null>) => {
    const route = resolveHorizonRoute(batchesIndex(), horizon.baseUrl);
    const url = urlWithCurrentQuery(route.url, parameters, ["before_id"]);

    router.get(
      url,
      {},
      {
        only: [
          "query",
          "filters",
          "batchStatusCounts",
          "batchQueryCapability",
          "listRevision",
          "batches",
          "horizon",
        ],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        reset: ["batches"],
      },
    );
  };
  const updateFilters = (nextFilters: BatchFilterValues) => {
    updateQuery({
      queue: nextFilters.queue,
      connection: nextFilters.connection,
      created: nextFilters.created,
    });
  };
  const updateStatus = (status: BatchStatus) => {
    updateQuery({ status });
  };
  const updateSort = (sort: string) => {
    const direction = filters.sort === sort && filters.direction === "asc" ? "desc" : "asc";

    updateQuery({ sort, direction });
  };
  const hasActiveQuery =
    query !== "" ||
    filters.queue !== null ||
    filters.connection !== null ||
    filters.created !== null;
  const emptyStatusCopy =
    filters.status === "all" ? undefined : emptyBatchStatusCopy[filters.status];
  const results = (
    <InfiniteScroll
      key={`${filterScope}:${batches.available}`}
      data="batches"
      itemsElement={batchItemsRef}
      onlyNext
      preserveUrl
      buffer={600}
      params={{ onBefore: refreshedBatches.onBeforeNextPage }}
    >
      <BatchTable
        batches={batches.data}
        horizonBaseUrl={horizon.baseUrl}
        available={batches.available}
        message={batches.message}
        hasNewEntries={refreshedBatches.hasNewEntries}
        onLoadNewEntries={refreshedBatches.loadNewEntries}
        emptyTitle={hasActiveQuery ? "No matching batches" : emptyStatusCopy?.title}
        emptyDescription={
          hasActiveQuery
            ? "No retained batches match the current query."
            : emptyStatusCopy?.description
        }
        showBatchActions
        sorting={
          batchQueryCapability.supported
            ? {
                key: filters.sort,
                direction: filters.direction,
                columns: batchSortColumns,
                onSort: updateSort,
              }
            : undefined
        }
        bodyRef={batchItemsRef}
      />
    </InfiniteScroll>
  );

  return (
    <>
      <Head title="Batches" />
      <Card>
        <ListPageHeader
          title="Batches"
          separated={false}
          actions={
            <BatchActionsControl horizonBaseUrl={horizon.baseUrl} counts={batchClearCounts} />
          }
        />
        <CardContent className="p-0">
          {batchQueryCapability.supported ? (
            <Tabs
              value={filters.status}
              onValueChange={(value) => updateStatus(value as BatchStatus)}
              className="gap-0"
            >
              <ResponsiveTabsHeader
                value={filters.status}
                items={batchTabs.map((tab) => ({
                  ...tab,
                  count: formatCount(batchStatusCounts[tab.value]),
                }))}
                ariaLabel="Batch status"
                separatedFromHeader
                onValueChange={(value) => {
                  if (value !== null && value !== "all" && value !== filters.status) {
                    updateStatus(value);
                  }
                }}
                className="w-full"
                triggerClassName="pt-0 pb-3"
              />

              <div className="flex min-h-10 items-center gap-2 border-y border-separator py-1.5 pr-2.5 pl-4 sm:px-6">
                <Field className="min-w-0 flex-1">
                  <FieldLabel className="sr-only">Search batches by name or ID</FieldLabel>
                  <InputGroup className="gap-1.5 border-0 bg-transparent! shadow-none has-[[data-slot=input-group-control]:focus-visible]:ring-0!">
                    <InputGroupInput
                      className="px-0!"
                      type="text"
                      role="searchbox"
                      inputMode="search"
                      value={search}
                      aria-label="Search batches by name or ID"
                      placeholder="Search batches by name or ID"
                      onChange={(event) => setSearch(event.target.value)}
                    />
                    <InputGroupAddon align="inline-start" className="pl-0">
                      <SearchIcon aria-hidden="true" />
                    </InputGroupAddon>
                    {search ? (
                      <InputGroupAddon align="inline-end" className="py-0 pr-0">
                        <InputGroupButton
                          size="icon-xs"
                          aria-label="Clear search"
                          onClick={() => setSearch("")}
                        >
                          <XIcon />
                        </InputGroupButton>
                      </InputGroupAddon>
                    ) : null}
                  </InputGroup>
                </Field>
                <BatchFilters
                  queues={queueOptions}
                  connections={connectionOptions}
                  values={{
                    queue: filters.queue,
                    connection: filters.connection,
                    created: filters.created,
                  }}
                  onChange={updateFilters}
                  attributionAvailable={batchQueryCapability.attributionSupported === true}
                  attributionMessage={batchQueryCapability.attributionMessage}
                />
              </div>

              <h2 className="sr-only">
                {batchTabs.find((tab) => tab.value === filters.status)?.label} batches
              </h2>
              <TabsContent value={filters.status}>{results}</TabsContent>
            </Tabs>
          ) : (
            <>
              <Alert variant="warning" className="m-4">
                <TriangleAlertIcon aria-hidden="true" />
                <AlertTitle>Exact batch queries unavailable</AlertTitle>
                <AlertDescription>
                  {batchQueryCapability.message ??
                    "This batch repository supports only Laravel's standard retained order."}
                </AlertDescription>
              </Alert>
              {results}
            </>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function BatchActionsControl({
  horizonBaseUrl,
  counts,
}: {
  horizonBaseUrl: string;
  counts: BatchClearCounts;
}) {
  if (counts.completeScan || counts.message === null) {
    return <BatchesActions horizonBaseUrl={horizonBaseUrl} counts={counts} />;
  }

  const label = `Batch actions unavailable. ${counts.message}`;

  return (
    <Tooltip>
      <TooltipTrigger
        render={
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className="cursor-not-allowed text-muted-foreground hover:bg-transparent hover:text-current active:translate-y-0"
            aria-label={label}
            aria-disabled="true"
          />
        }
      >
        <EllipsisIcon />
      </TooltipTrigger>
      <TooltipContent>{counts.message}</TooltipContent>
    </Tooltip>
  );
}

function batchFilterOptions(values: readonly string[], active: string | null): string[] {
  const options = active === null ? values : [...values, active];

  return Array.from(new Set(options.filter((value): value is string => value !== ""))).sort(
    (left, right) => left.localeCompare(right, undefined, { sensitivity: "base" }),
  );
}

export default BatchesIndex;
