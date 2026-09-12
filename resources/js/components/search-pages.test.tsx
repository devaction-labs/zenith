import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";

import BatchesIndex from "@/pages/batches/index";
import FailedJobsIndex from "@/pages/failed-jobs/index";
import JobsIndex from "@/pages/jobs/index";
import MonitoringIndex from "@/pages/monitoring/index";
import { CardHeader, CardTitle } from "@/components/ui/card";
import type { JobRow } from "@/types/jobs";

const inertia = vi.hoisted(() => ({
  infiniteScrollProps: vi.fn(),
  pollStart: vi.fn(),
  pollStop: vi.fn(),
  routerCancelAll: vi.fn(),
  routerGet: vi.fn(),
  routerReload: vi.fn(),
  routerReplace: vi.fn(),
  usePoll: vi.fn(),
  scrollProps: {} as Record<string, unknown>,
}));

const batchFilters = vi.hoisted(() => ({
  props: vi.fn(),
}));
const jobFilters = vi.hoisted(() => ({
  props: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  InfiniteScroll: ({ children, ...props }: { children: ReactNode }) => {
    inertia.infiniteScrollProps(props);

    return children;
  },
  Link: ({ children, href }: { children: ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
  router: {
    cancelAll: inertia.routerCancelAll,
    delete: vi.fn(),
    get: inertia.routerGet,
    post: vi.fn(),
    reload: inertia.routerReload,
    replace: inertia.routerReplace,
  },
  usePage: () => ({
    props: { navigationCounts: undefined },
    scrollProps: inertia.scrollProps,
  }),
  usePoll: inertia.usePoll,
  useForm: () => ({
    clearErrors: vi.fn(),
    data: { tag: "" },
    errors: {},
    post: vi.fn(),
    processing: false,
    reset: vi.fn(),
    setData: vi.fn(),
  }),
}));

vi.mock("@/layouts/horizon-layout", () => ({
  useAutoLoadPreference: () => ({ autoLoad: true }),
  useResolvedNavigationCounts: () => undefined,
}));

vi.mock("@/components/batches/batch-filters", () => ({
  BatchFilters: (props: unknown) => {
    batchFilters.props(props);

    return null;
  },
}));

vi.mock("@/components/jobs/job-filters", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/components/jobs/job-filters")>();

  return {
    ...original,
    JobFilters: (props: {
      onFilterChange: (key: "job" | "queue" | "connection" | "state", value: string | null) => void;
      onIntent: () => void;
    }) => {
      jobFilters.props(props);

      return (
        <button
          type="button"
          aria-label="Filter jobs"
          onFocus={props.onIntent}
          onPointerEnter={props.onIntent}
          onClick={() => {
            props.onIntent();
            props.onFilterChange("job", "App\\Jobs\\ArchivedExport");
          }}
        >
          Filter jobs
        </button>
      );
    },
  };
});

const horizon = { baseUrl: "/horizon", pollInterval: 0, status: "running" as const };
const exactBatchQueryProps = {
  listRevision: "[0,null]",
  filters: {
    queue: null,
    connection: null,
    created: null,
    status: "pending" as const,
    sort: "progress" as const,
    direction: "desc" as const,
  },
  batchStatusCounts: {
    all: 101,
    pending: 26,
    finished: 25,
    failures: 25,
    cancelled: 25,
  },
  batchQueryCapability: {
    supported: true,
    message: null,
    attributionSupported: true,
    attributionMessage: null,
  },
};
const exactJobQueryProps = {
  listRevision: "[0,null]",
  filters: { job: null, queue: null, connection: null, state: null, tag: null },
  filterCatalog: {
    available: true,
    jobs: [],
    queues: [],
    connections: [],
    message: null,
  },
  querySignature: "unfiltered-jobs",
};

function failedJobActions(hasFailedJobs: boolean) {
  return {
    hasFailedJobs,
    retryable: hasFailedJobs,
    retryUnavailableReason: null,
    clearable: hasFailedJobs,
    clearUnavailableReason: null,
  };
}

function jobRow(
  id: string,
  shortName: string,
  status: "completed" | "failed",
  overrides: Partial<JobRow> = {},
): JobRow {
  const occurredAt = 1_784_281_006;

  return {
    id,
    index: 0,
    name: `App\\Jobs\\${shortName}`,
    shortName,
    connection: "redis",
    queue: "default",
    status,
    tags: [],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000,
    reservedAt: 1_784_281_001,
    completedAt: status === "completed" ? occurredAt : null,
    failedAt: status === "failed" ? occurredAt : null,
    runtime: 5,
    occurredAt,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: status === "failed",
    ...overrides,
  };
}

function expectLinkBefore(first: string, second: string) {
  expect(
    screen
      .getByRole("link", { name: first })
      .compareDocumentPosition(screen.getByRole("link", { name: second })),
  ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
}

describe("Inertia search pages", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    inertia.infiniteScrollProps.mockReset();
    inertia.pollStart.mockReset();
    inertia.pollStop.mockReset();
    inertia.routerCancelAll.mockReset();
    inertia.routerGet.mockReset();
    inertia.routerReload.mockReset();
    inertia.routerReplace.mockReset();
    inertia.routerReplace.mockImplementation(({ url }: { url?: string }) => {
      if (url !== undefined) {
        window.history.replaceState(window.history.state, "", url);
      }
    });
    inertia.usePoll.mockReset();
    batchFilters.props.mockReset();
    jobFilters.props.mockReset();
    inertia.usePoll.mockReturnValue({ start: inertia.pollStart, stop: inertia.pollStop });
    inertia.scrollProps = {};
    window.history.replaceState({}, "", "/horizon");
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("debounces full failed-job tag searches and resets scroll state", () => {
    render(
      <FailedJobsIndex
        horizon={horizon}
        query=""
        {...exactJobQueryProps}
        actions={failedJobActions(false)}
        jobs={{
          data: [],
          total: 0,
          available: true,
          message: null,
        }}
      />,
    );

    fireEvent.change(screen.getByRole("searchbox", { name: "Filter failed jobs by exact tag" }), {
      target: { value: "tenant:42" },
    });
    vi.advanceTimersByTime(499);
    expect(inertia.routerGet).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/failed?tag=tenant%3A42",
      {},
      expect.objectContaining({
        only: ["query", "filters", "querySignature", "listRevision", "jobs", "horizon"],
        reset: ["jobs"],
        replace: true,
      }),
    );
  });

  it("does not let a pending failed-job tag search undo a selected filter", () => {
    render(
      <FailedJobsIndex
        horizon={horizon}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        filterCatalog={{
          available: true,
          jobs: [{ value: "App\\Jobs\\ArchivedExport", label: "ArchivedExport" }],
          queues: [],
          connections: [],
          message: null,
        }}
        querySignature="failed-query"
        listRevision="[0,null]"
        actions={failedJobActions(false)}
        jobs={{
          data: [],
          total: 0,
          available: true,
          message: null,
        }}
      />,
    );

    fireEvent.change(screen.getByRole("searchbox", { name: "Filter failed jobs by exact tag" }), {
      target: { value: "tenant:42" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Filter jobs" }));
    vi.advanceTimersByTime(500);

    expect(inertia.routerGet).toHaveBeenLastCalledWith(
      "/horizon/failed?tag=tenant%3A42&filter_job=App%5CJobs%5CArchivedExport",
      {},
      expect.any(Object),
    );
  });

  it("queries the complete retained job source with server-provided filter options", () => {
    render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        filterCatalog={{
          available: true,
          jobs: [{ value: "App\\Jobs\\ArchivedExport", label: "ArchivedExport" }],
          queues: ["archive"],
          connections: ["redis"],
          message: "Some retained job references have not been hydrated yet.",
        }}
        querySignature="completed-query"
        listRevision="[0,null]"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    expect(
      screen.getByRole("searchbox", { name: "Search completed jobs by class or ID" }),
    ).toBeVisible();
    expect(jobFilters.props).toHaveBeenCalledWith(
      expect.objectContaining({
        filterKeys: ["job", "queue", "connection", "tag"],
        options: {
          job: [{ value: "App\\Jobs\\ArchivedExport", label: "ArchivedExport" }],
          queue: [{ value: "archive", label: "archive" }],
          connection: [{ value: "redis", label: "redis" }],
        },
        description: "Some retained job references have not been hydrated yet.",
      }),
    );

    fireEvent.change(
      screen.getByRole("searchbox", { name: "Search completed jobs by class or ID" }),
      {
        target: { value: "Production" },
      },
    );
    fireEvent.click(screen.getByRole("button", { name: "Filter jobs" }));

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/jobs/completed?query=Production&filter_job=App%5CJobs%5CArchivedExport",
      {},
      expect.objectContaining({
        only: ["query", "filters", "querySignature", "listRevision", "jobs", "horizon"],
        reset: ["jobs"],
        replace: true,
      }),
    );

    vi.advanceTimersByTime(500);

    expect(inertia.routerGet).toHaveBeenLastCalledWith(
      "/horizon/jobs/completed?query=Production&filter_job=App%5CJobs%5CArchivedExport",
      {},
      expect.any(Object),
    );
  });

  it("preserves rapid changes across different retained-job filter dimensions", () => {
    render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        filterCatalog={{
          available: true,
          jobs: [{ value: "App\\Jobs\\ArchivedExport", label: "ArchivedExport" }],
          queues: ["archive"],
          connections: ["redis"],
          message: null,
        }}
        querySignature="completed-query"
        listRevision="[0,null]"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    const firstControls = jobFilters.props.mock.lastCall?.[0] as {
      onFilterChange: (key: "job" | "queue" | "connection" | "state", value: string | null) => void;
    };

    act(() => firstControls.onFilterChange("job", "App\\Jobs\\ArchivedExport"));

    const updatedControls = jobFilters.props.mock.lastCall?.[0] as typeof firstControls;

    act(() => updatedControls.onFilterChange("queue", "archive"));

    expect(inertia.routerGet).toHaveBeenLastCalledWith(
      "/horizon/jobs/completed?filter_job=App%5CJobs%5CArchivedExport&filter_queue=archive",
      {},
      expect.any(Object),
    );
  });

  it("debounces full retained-job searches and resets scroll state", () => {
    render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        {...exactJobQueryProps}
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    fireEvent.change(
      screen.getByRole("searchbox", { name: "Search completed jobs by class or ID" }),
      {
        target: { value: "ProductionOnly" },
      },
    );
    vi.advanceTimersByTime(499);
    expect(inertia.routerGet).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/jobs/completed?query=ProductionOnly",
      {},
      expect.objectContaining({
        only: ["query", "filters", "querySignature", "listRevision", "jobs", "horizon"],
        reset: ["jobs"],
        replace: true,
      }),
    );
  });

  it("preserves a newer typed search when an older response arrives", () => {
    const props = {
      horizon,
      type: "completed" as const,
      pendingCounts: null,
      filters: { job: null, queue: null, connection: null, state: null, tag: null },
      filterCatalog: exactJobQueryProps.filterCatalog,
      listRevision: "[0,null]",
      jobs: { data: [], total: 0, available: true, message: null },
    };
    const { rerender } = render(
      <JobsIndex {...props} query="" querySignature="completed-empty-query" />,
    );
    const search = screen.getByRole("searchbox", {
      name: "Search completed jobs by class or ID",
    });

    fireEvent.change(search, { target: { value: "A" } });
    vi.advanceTimersByTime(500);
    fireEvent.change(search, { target: { value: "AB" } });

    rerender(<JobsIndex {...props} query="A" querySignature="completed-a-query" />);

    expect(
      screen.getByRole("searchbox", {
        name: "Search completed jobs by class or ID",
      }),
    ).toHaveValue("AB");
    vi.advanceTimersByTime(500);
    expect(inertia.routerGet).toHaveBeenLastCalledWith(
      "/horizon/jobs/completed?query=AB",
      {},
      expect.objectContaining({
        only: ["query", "filters", "querySignature", "listRevision", "jobs", "horizon"],
      }),
    );
  });

  it("keeps the filter dialog available while its catalog is deferred or unavailable", () => {
    const { rerender } = render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        querySignature="completed-preparing-filters"
        listRevision="[0,null]"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    expect(jobFilters.props).toHaveBeenLastCalledWith(
      expect.objectContaining({
        description: "Preparing exact server-side filters.",
      }),
    );
    expect(screen.getByRole("button", { name: "Filter jobs" })).toBeEnabled();
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0);

    rerender(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        filterCatalog={{
          available: false,
          jobs: [],
          queues: [],
          connections: [],
          message: "Global job filters are currently unavailable.",
        }}
        querySignature="completed-unavailable-filters"
        listRevision="[0,null]"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    expect(jobFilters.props).toHaveBeenLastCalledWith(
      expect.objectContaining({
        description: "Global job filters are currently unavailable.",
      }),
    );
    expect(screen.getByRole("button", { name: "Filter jobs" })).toBeEnabled();
  });

  it("keeps the same stable filter dialog available on failed jobs", () => {
    render(
      <FailedJobsIndex
        horizon={horizon}
        query=""
        filters={{ job: null, queue: null, connection: null, state: null, tag: null }}
        querySignature="failed-preparing-filters"
        listRevision="[0,null]"
        actions={failedJobActions(false)}
        jobs={{
          data: [],
          total: 0,
          available: true,
          message: null,
        }}
      />,
    );

    expect(jobFilters.props).toHaveBeenLastCalledWith(
      expect.objectContaining({
        description: "Preparing exact server-side filters.",
      }),
    );
    expect(screen.getByRole("button", { name: "Filter jobs" })).toBeEnabled();
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0);
  });

  it("sorts only the loaded job rows without requesting the complete source", () => {
    window.history.replaceState(
      {},
      "",
      "/horizon/jobs/completed?filter_queue=mail&starting_at=opaque-cursor",
    );
    const slowerJob = jobRow("job-001", "SlowerJob", "completed", { runtime: 5 });
    const fasterJob = jobRow("job-002", "FasterJob", "completed", { runtime: 1 });

    render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        filters={{ job: null, queue: "mail", connection: null, state: null, tag: null }}
        filterCatalog={{
          available: true,
          jobs: [],
          queues: ["mail"],
          connections: [],
          message: null,
        }}
        querySignature="completed-mail"
        listRevision={slowerJob.id}
        jobs={{ data: [slowerJob, fasterJob], total: 50_000, available: true, message: null }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Runtime ascending" }));

    expectLinkBefore("FasterJob", "SlowerJob");
    expect(inertia.routerGet).not.toHaveBeenCalled();
    expect(inertia.routerReplace).toHaveBeenCalledWith(
      expect.objectContaining({
        url: "/horizon/jobs/completed?filter_queue=mail&starting_at=opaque-cursor&sort=runtime&direction=asc",
        preserveScroll: true,
        preserveState: true,
      }),
    );
    expect(inertia.infiniteScrollProps).toHaveBeenLastCalledWith(
      expect.objectContaining({ buffer: 600 }),
    );
    expect(inertia.infiniteScrollProps.mock.lastCall?.[0]).not.toHaveProperty("loading");
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0);
  });

  it("reverses a browser-local job sort and keeps live pending State non-sortable", () => {
    window.history.replaceState({}, "", "/horizon/jobs/completed?sort=runtime&direction=asc");
    const { rerender } = render(
      <JobsIndex
        horizon={horizon}
        type="completed"
        pendingCounts={null}
        query=""
        {...exactJobQueryProps}
        querySignature="completed-runtime"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Runtime descending" }));

    expect(inertia.routerGet).not.toHaveBeenCalled();
    expect(inertia.routerReplace).toHaveBeenCalledWith(
      expect.objectContaining({
        url: "/horizon/jobs/completed?sort=runtime&direction=desc",
      }),
    );

    rerender(
      <JobsIndex
        horizon={horizon}
        type="pending"
        pendingCounts={null}
        query=""
        {...exactJobQueryProps}
        querySignature="pending"
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    expect(screen.getByRole("columnheader", { name: "State" })).toBeVisible();
    expect(screen.queryByRole("button", { name: /Sort by State/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sort by Job ascending" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Sort by Queued ascending" })).toBeVisible();
  });

  it("sorts only the loaded failed jobs while preserving the exact tag and filters", () => {
    window.history.replaceState(
      {},
      "",
      "/horizon/failed?tag=tenant%3A42&filter_connection=redis&starting_at=opaque-cursor",
    );
    const laterFailure = jobRow("failed-001", "LaterFailureJob", "failed", {
      failedAt: 1_784_281_010,
    });
    const earlierFailure = jobRow("failed-002", "EarlierFailureJob", "failed", {
      failedAt: 1_784_281_001,
    });

    render(
      <FailedJobsIndex
        horizon={horizon}
        query="tenant:42"
        filters={{ job: null, queue: null, connection: "redis", state: null, tag: null }}
        filterCatalog={{
          available: true,
          jobs: [],
          queues: [],
          connections: ["redis"],
          message: null,
        }}
        querySignature="failed-tenant"
        listRevision={laterFailure.id}
        actions={failedJobActions(false)}
        jobs={{
          data: [laterFailure, earlierFailure],
          total: 50_000,
          available: true,
          message: null,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Failed ascending" }));

    expectLinkBefore("EarlierFailureJob", "LaterFailureJob");
    expect(inertia.routerGet).not.toHaveBeenCalled();
    expect(inertia.routerReplace).toHaveBeenCalledWith(
      expect.objectContaining({
        url: "/horizon/failed?tag=tenant%3A42&filter_connection=redis&starting_at=opaque-cursor&sort=failedAt&direction=asc",
        preserveScroll: true,
        preserveState: true,
      }),
    );
    expect(inertia.infiniteScrollProps).toHaveBeenLastCalledWith(
      expect.objectContaining({ buffer: 600 }),
    );
    expect(inertia.infiniteScrollProps.mock.lastCall?.[0]).not.toHaveProperty("loading");
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0);
  });

  it("debounces batch searches through the handoff search field", () => {
    render(
      <BatchesIndex
        horizon={horizon}
        query="imports"
        {...exactBatchQueryProps}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    fireEvent.change(screen.getByLabelText("Search batches by name or ID"), {
      target: { value: "" },
    });
    vi.advanceTimersByTime(500);

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/batches",
      {},
      expect.objectContaining({
        only: [
          "query",
          "filters",
          "batchStatusCounts",
          "batchQueryCapability",
          "listRevision",
          "batches",
          "horizon",
        ],
        reset: ["batches"],
        replace: true,
      }),
    );
  });

  it("keeps retained batch filter options available before scrolling and preserves active missing values", () => {
    render(
      <BatchesIndex
        horizon={horizon}
        query=""
        {...exactBatchQueryProps}
        filters={{
          ...exactBatchQueryProps.filters,
          queue: "legacy",
          connection: "archive",
        }}
        batchFilterCatalog={{
          available: true,
          complete: true,
          message: null,
          queues: ["imports"],
          connections: ["redis"],
        }}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    expect(batchFilters.props).toHaveBeenCalledWith(
      expect.objectContaining({
        queues: ["imports", "legacy"],
        connections: ["archive", "redis"],
        values: {
          queue: "legacy",
          connection: "archive",
          created: null,
        },
      }),
    );
  });

  it("stops list polling while a server-backed search is waiting to settle", () => {
    const { unmount } = render(
      <FailedJobsIndex
        horizon={{ ...horizon, pollInterval: 5_000 }}
        query=""
        {...exactJobQueryProps}
        actions={failedJobActions(false)}
        jobs={{
          data: [],
          total: 0,
          available: true,
          message: null,
        }}
      />,
    );

    inertia.pollStop.mockClear();
    fireEvent.change(screen.getByRole("searchbox", { name: "Filter failed jobs by exact tag" }), {
      target: { value: "tenant:42" },
    });

    expect(inertia.pollStop).toHaveBeenCalled();

    unmount();
    inertia.pollStop.mockClear();

    render(
      <BatchesIndex
        horizon={{ ...horizon, pollInterval: 5_000 }}
        query=""
        {...exactBatchQueryProps}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    inertia.pollStop.mockClear();
    fireEvent.change(screen.getByLabelText("Search batches by name or ID"), {
      target: { value: "imports" },
    });

    expect(inertia.pollStop).toHaveBeenCalled();
  });

  it("queries the complete batch source for status tabs and sortable columns", () => {
    render(
      <BatchesIndex
        horizon={horizon}
        query=""
        {...exactBatchQueryProps}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    const statusTabs = screen.getAllByRole("tab");

    expect(statusTabs).toHaveLength(4);
    [
      ["Pending", "26"],
      ["Complete", "25"],
      ["Incomplete", "25"],
      ["Cancelled", "25"],
    ].forEach(([label, count], index) => {
      expect(within(statusTabs[index]).getByText(label)).toBeVisible();
      expect(within(statusTabs[index]).getByText(count)).toBeVisible();
    });
    expect(screen.queryByRole("tab", { name: /All/ })).not.toBeInTheDocument();
    expect(statusTabs[0]).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("No pending batches")).toBeVisible();
    expect(inertia.infiniteScrollProps).toHaveBeenLastCalledWith(
      expect.objectContaining({ buffer: 600 }),
    );
    expect(inertia.infiniteScrollProps.mock.lastCall?.[0]).not.toHaveProperty("loading");
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0);

    fireEvent.click(screen.getByRole("tab", { name: /Incomplete.*25/ }));

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/batches?status=failures",
      {},
      expect.objectContaining({ reset: ["batches"], replace: true }),
    );

    inertia.routerGet.mockClear();
    fireEvent.click(screen.getByRole("button", { name: "Sort by Total ascending" }));

    expect(inertia.routerGet).toHaveBeenCalledWith(
      "/horizon/batches?sort=totalJobs&direction=asc",
      {},
      expect.objectContaining({ reset: ["batches"], replace: true }),
    );
  });

  it("refreshes exact batch status counts with each list poll", () => {
    render(
      <BatchesIndex
        horizon={{ ...horizon, pollInterval: 5_000 }}
        query=""
        {...exactBatchQueryProps}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    const pollOptions = inertia.usePoll.mock.calls.at(-1)?.[1] as () => {
      only: string[];
    };

    expect(pollOptions().only).toContain("batchStatusCounts");
  });

  it.each(["pending", "completed", "silenced"] as const)(
    "keeps the %s job filter catalog out of list polling",
    (type) => {
      render(
        <JobsIndex
          horizon={{ ...horizon, pollInterval: 5_000 }}
          type={type}
          pendingCounts={null}
          query=""
          {...exactJobQueryProps}
          jobs={{ data: [], total: 0, available: true, message: null }}
        />,
      );

      const pollOptions = inertia.usePoll.mock.calls.at(-1)?.[1] as () => {
        only: string[];
      };

      expect(pollOptions().only).not.toContain("filterCatalog");

      if (type === "pending") {
        expect(pollOptions().only).toContain("pendingCounts");
      }
    },
  );

  it("keeps the failed-job filter catalog out of list polling", () => {
    render(
      <FailedJobsIndex
        horizon={{ ...horizon, pollInterval: 5_000 }}
        query=""
        {...exactJobQueryProps}
        actions={failedJobActions(false)}
        jobs={{ data: [], total: 0, available: true, message: null }}
      />,
    );

    const pollOptions = inertia.usePoll.mock.calls.at(-1)?.[1] as () => {
      only: string[];
    };

    expect(pollOptions().only).not.toContain("filterCatalog");
  });

  it.each([
    ["completed jobs", "completed"],
    ["failed jobs", "failed"],
  ] as const)(
    "refreshes the %s filter catalog when the filter trigger is hovered",
    (_label, type) => {
      const props = {
        horizon: { ...horizon, pollInterval: 5_000 },
        query: "",
        ...exactJobQueryProps,
        filterCatalog: undefined,
        jobs: { data: [], total: 0, available: true, message: null },
      };

      if (type === "failed") {
        render(<FailedJobsIndex {...props} actions={failedJobActions(false)} />);
      } else {
        render(<JobsIndex {...props} type={type} pendingCounts={null} />);
      }

      fireEvent.pointerEnter(screen.getByRole("button", { name: "Filter jobs" }));

      expect(inertia.routerReload).toHaveBeenCalledOnce();
      expect(inertia.routerReload).toHaveBeenCalledWith(
        expect.objectContaining({
          only: ["filterCatalog"],
          preserveUrl: true,
          showProgress: false,
          onCancelToken: expect.any(Function),
          onFinish: expect.any(Function),
        }),
      );
    },
  );

  it("scopes an incomplete clear preflight to the disabled batch action", () => {
    const message =
      "Only the newest 1,000 retained batches were inspected. Clear actions are disabled.";

    render(
      <BatchesIndex
        horizon={horizon}
        query=""
        {...exactBatchQueryProps}
        batchClearCounts={{
          incomplete: 0,
          complete: 0,
          finished: 0,
          cancelled: 0,
          available: false,
          completeScan: false,
          message,
        }}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    expect(screen.queryByText("Retained history is incomplete")).not.toBeInTheDocument();
    expect(
      screen.getByRole("button", {
        name: `Batch actions unavailable. ${message}`,
      }),
    ).toHaveAttribute("aria-disabled", "true");
  });

  it("hides exact-query controls for unsupported batch repositories", () => {
    render(
      <BatchesIndex
        horizon={horizon}
        query=""
        {...exactBatchQueryProps}
        batchQueryCapability={{
          supported: false,
          message: "Exact retained batch queries require Laravel's database batch repository.",
        }}
        batches={{ data: [], available: true, complete: true, message: null }}
      />,
    );

    expect(screen.queryByLabelText("Search batches by name or ID")).not.toBeInTheDocument();
    expect(screen.queryByRole("tablist", { name: "Batch status" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Sort by/ })).not.toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent(
      "Exact retained batch queries require Laravel's database batch repository.",
    );
  });

  it("keeps action and plain card headers on the same 54px rhythm", () => {
    const { rerender } = render(
      <MonitoringIndex horizon={horizon} tags={{ data: [], available: true, message: null }} />,
    );

    const monitoringHeader = screen
      .getByRole("button", { name: "Monitor Tag" })
      .closest('[data-slot="card-header"]');

    expect(monitoringHeader).toHaveClass(
      "flex",
      "min-h-[54px]",
      "items-center",
      "justify-between",
      "gap-3",
    );

    rerender(
      <CardHeader>
        <CardTitle>Completed Jobs</CardTitle>
      </CardHeader>,
    );

    expect(screen.getByText("Completed Jobs").closest('[data-slot="card-header"]')).toHaveClass(
      "min-h-[54px]",
    );
  });
});
