import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { QueueActivityTabs } from "@/components/queues/queue-activity-tabs";
import type { JobRow } from "@/types/jobs";
import type { QueueSummary } from "@/types/queues";

const routerGet = vi.hoisted(() => vi.fn());
const routerPrefetch = vi.hoisted(() => vi.fn());
const routerReplace = vi.hoisted(() => vi.fn());
const routerVisit = vi.hoisted(() => vi.fn());
const infiniteScrollProps = vi.hoisted(() => vi.fn());

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");
  const mocks = inertiaTestMocks();

  return {
    ...mocks,
    config: {
      get: () => 75,
    },
    InfiniteScroll: ({ children, ...props }: { children: ReactNode }) => {
      infiniteScrollProps(props);

      return children;
    },
    router: {
      ...mocks.router,
      get: routerGet,
      prefetch: routerPrefetch,
      replace: routerReplace,
      visit: routerVisit,
    },
  };
});

vi.mock("@/components/batches/queue-batches-actions", () => ({
  QueueBatchesActions: () => null,
}));
vi.mock("@/components/jobs/pending-jobs-actions", () => ({
  PendingJobsActions: () => null,
}));
vi.mock("@/components/queues/queue-actions-menu", () => ({
  QueueActionsMenu: () => null,
}));

const summary = {
  pendingJobs: 1,
  pendingComplete: true,
  completedAvailable: true,
  completedJobs: 1,
  completedComplete: true,
  failedJobs: 0,
  failedComplete: true,
  silencedJobs: 0,
  silencedComplete: true,
  batches: 0,
  batchesComplete: true,
} as QueueSummary;
const refreshProps = {
  hasNewEntries: false,
  onLoadNewEntries: vi.fn(),
  onBeforeNextPage: vi.fn(),
};

function completedJob(
  id: string,
  shortName: string,
  runtime = 1,
  completedAt = 1_784_281_002,
): JobRow {
  return {
    id,
    index: 0,
    name: `App\\Jobs\\${shortName}`,
    shortName,
    connection: "redis",
    queue: "default",
    status: "completed",
    tags: [],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000,
    reservedAt: 1_784_281_001,
    completedAt,
    failedAt: null,
    runtime,
    occurredAt: 1_784_281_002,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: false,
  };
}

describe("QueueActivityTabs", () => {
  beforeEach(() => {
    infiniteScrollProps.mockReset();
    routerGet.mockReset();
    routerPrefetch.mockReset();
    routerReplace.mockReset();
    routerVisit.mockReset();
    routerReplace.mockImplementation(({ url }: { url?: string }) => {
      if (url !== undefined) {
        window.history.replaceState(window.history.state, "", url);
      }
    });
    window.history.replaceState({}, "", "/horizon/queues/default");
  });

  it("reuses the exact prefetched visit when switching activity tabs", () => {
    vi.useFakeTimers();

    try {
      render(
        <QueueActivityTabs
          queue="default"
          tab="pending"
          view="overview"
          summary={summary}
          activity={{
            data: [],
            total: 0,
            complete: true,
            available: true,
            warming: false,
            message: null,
          }}
          horizonBaseUrl="/horizon"
          querySignature="pending-default"
          {...refreshProps}
        />,
      );

      const completedTab = screen.getByRole("tab", { name: "Completed Jobs 1" });

      fireEvent.mouseEnter(completedTab);

      expect(routerPrefetch).not.toHaveBeenCalled();

      vi.advanceTimersByTime(100);
      fireEvent.click(completedTab);

      expect(routerPrefetch).toHaveBeenCalledOnce();
      expect(routerVisit).toHaveBeenCalledOnce();
      expect(routerPrefetch.mock.calls[0]?.[0]).toBe("/horizon/queues/default?tab=completed");
      expect(routerVisit.mock.calls[0]?.[0]).toBe("/horizon/queues/default?tab=completed");
      expect(routerVisit.mock.calls[0]?.[1]).toBe(routerPrefetch.mock.calls[0]?.[1]);
      expect(routerVisit.mock.calls[0]?.[1]).toMatchObject({
        only: [
          "activity",
          "tab",
          "view",
          "querySignature",
          "listRevision",
          "horizon",
          "navigationCounts",
        ],
      });
    } finally {
      vi.useRealTimers();
    }
  });

  it("cancels a queue activity prefetch when hover ends before Inertia's delay", () => {
    vi.useFakeTimers();

    try {
      render(
        <QueueActivityTabs
          queue="default"
          tab="pending"
          view="overview"
          summary={summary}
          activity={{
            data: [],
            total: 0,
            complete: true,
            available: true,
            warming: false,
            message: null,
          }}
          horizonBaseUrl="/horizon"
          querySignature="pending-default"
          {...refreshProps}
        />,
      );

      const completedTab = screen.getByRole("tab", { name: "Completed Jobs 1" });

      fireEvent.mouseEnter(completedTab);
      fireEvent.mouseLeave(completedTab);
      vi.advanceTimersByTime(100);

      expect(routerPrefetch).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });

  it("exposes only the loaded-row sort columns supported by each job tab", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="pending"
        view="overview"
        summary={summary}
        activity={{
          data: [],
          total: 0,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="pending-default"
        {...refreshProps}
      />,
    );

    expect(screen.queryByRole("searchbox")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Filter jobs/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sort by Job ascending" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Sort by Queued ascending" })).toBeVisible();
    expect(screen.getByRole("columnheader", { name: "State" })).toBeVisible();
    expect(screen.queryByRole("button", { name: /Sort by State/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Sort by Runtime/ })).not.toBeInTheDocument();
    expect(infiniteScrollProps).toHaveBeenLastCalledWith(expect.objectContaining({ buffer: 600 }));
    expect(infiniteScrollProps.mock.lastCall?.[0].params.onBefore).toBe(
      refreshProps.onBeforeNextPage,
    );
    expect(infiniteScrollProps.mock.lastCall?.[0]).not.toHaveProperty("loading");
  });

  it("shows the latest completed queue jobs first by default", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [
            completedJob("completed-older", "OlderJob", 1, 1_784_281_002),
            completedJob("completed-latest", "LatestJob", 1, 1_784_281_010),
          ],
          total: 2,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-default"
        {...refreshProps}
      />,
    );

    expect(
      screen
        .getByRole("link", { name: "LatestJob" })
        .compareDocumentPosition(screen.getByRole("link", { name: "OlderJob" })),
    ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    expect(screen.getByRole("columnheader", { name: /Completed/ })).toHaveAttribute(
      "aria-sort",
      "descending",
    );
  });

  it("sorts only loaded queue rows while preserving the queue URL without a request", () => {
    window.history.replaceState(
      {},
      "",
      "/horizon/queues/default?tab=completed&view=metrics&starting_at=opaque-cursor",
    );

    render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="metrics"
        summary={summary}
        activity={{
          data: [
            completedJob("completed-slow", "SlowJob", 5),
            completedJob("completed-fast", "FastJob", 1),
          ],
          total: 50_000,
          complete: false,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-default"
        {...refreshProps}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Runtime ascending" }));

    expect(
      screen
        .getByRole("link", { name: "FastJob" })
        .compareDocumentPosition(screen.getByRole("link", { name: "SlowJob" })),
    ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    expect(routerGet).not.toHaveBeenCalled();
    expect(routerReplace).toHaveBeenCalledWith(
      expect.objectContaining({
        url: "/horizon/queues/default?tab=completed&view=metrics&starting_at=opaque-cursor&sort=runtime&direction=asc",
        preserveScroll: true,
        preserveState: true,
      }),
    );
  });

  it("renders the queue activity rows supplied by Inertia without reconstructing a stale tail", () => {
    window.history.replaceState(
      {},
      "",
      "/horizon/queues/default?tab=completed&sort=name&direction=asc",
    );
    const first = completedJob("completed-001", "FirstJob");
    const stale = completedJob("completed-051", "StaleTailJob");
    const later = completedJob("completed-052", "LaterTailJob");
    const inserted = completedJob("completed-000", "InsertedJob");
    const { rerender } = render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [first, stale, later],
          total: 52,
          complete: false,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-runtime"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("link", { name: "StaleTailJob" })).toBeVisible();

    rerender(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [inserted, first],
          total: 52,
          complete: false,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-runtime"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("link", { name: "InsertedJob" })).toBeVisible();
    expect(screen.queryByRole("link", { name: "StaleTailJob" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "LaterTailJob" })).not.toBeInTheDocument();
  });

  it("keeps batch attribution implementation details out of queue activity", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="batches"
        view="overview"
        summary={summary}
        activity={{
          data: [],
          total: 0,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        batchAttributionAvailable
        querySignature="batches-default"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("tab", { name: /Batches/ })).toBeVisible();
    expect(screen.queryByText("Batch destination attribution")).not.toBeInTheDocument();
    expect(
      screen.queryByText(/historical defaults inferred from the queue configuration/),
    ).not.toBeInTheDocument();
    expect(screen.getByText("No attributed batches")).toBeVisible();
  });

  it("hides queue batch activity until attribution storage is available", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="pending"
        view="overview"
        summary={summary}
        activity={{
          data: [],
          total: 0,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        batchAttributionAvailable={false}
        querySignature="pending-default"
        {...refreshProps}
      />,
    );

    expect(screen.queryByRole("tab", { name: /Batches/ })).not.toBeInTheDocument();
  });

  it("renders one error when retained activity is unavailable", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [],
          total: 0,
          complete: false,
          available: false,
          warming: false,
          message: "Retained completed jobs are currently unavailable.",
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-default"
        {...refreshProps}
      />,
    );

    expect(screen.getAllByText("Retained completed jobs are currently unavailable.")).toHaveLength(
      1,
    );
  });

  it("shows a neutral preparing state while retained activity is warming", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [],
          total: 0,
          complete: false,
          available: false,
          warming: true,
          message: "The retained job index is currently warming.",
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-default"
        {...refreshProps}
      />,
    );

    expect(screen.getByText("Preparing completed jobs")).toBeVisible();
    expect(
      screen.getByText("Horizon is updating retained history. Results will appear automatically."),
    ).toBeVisible();
    expect(screen.queryByText("Jobs unavailable")).not.toBeInTheDocument();
    expect(
      screen.queryByText("The retained job index is currently warming."),
    ).not.toBeInTheDocument();
  });

  it("keeps stale rows visible without an error while retained activity is warming", () => {
    render(
      <QueueActivityTabs
        queue="default"
        tab="completed"
        view="overview"
        summary={summary}
        activity={{
          data: [completedJob("completed-stale", "StaleJob")],
          total: 1,
          complete: false,
          available: false,
          warming: true,
          message: "The retained job index is currently warming.",
        }}
        horizonBaseUrl="/horizon"
        querySignature="completed-default"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("link", { name: "StaleJob" })).toBeVisible();
    expect(screen.queryByText("Jobs unavailable")).not.toBeInTheDocument();
    expect(
      screen.queryByText("The retained job index is currently warming."),
    ).not.toBeInTheDocument();
    expect(screen.queryByText("Preparing completed jobs")).not.toBeInTheDocument();
  });

  it("distinguishes an unavailable completed count from a bounded lower bound", () => {
    const { rerender } = render(
      <QueueActivityTabs
        queue="default"
        tab="pending"
        view="overview"
        summary={{
          ...summary,
          completedAvailable: false,
          completedJobs: null,
          completedComplete: false,
        }}
        activity={{
          data: [],
          total: 0,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="pending-default"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("tab", { name: "Completed Jobs —" })).toBeVisible();
    expect(screen.queryByRole("tab", { name: "Completed Jobs 0+" })).not.toBeInTheDocument();

    rerender(
      <QueueActivityTabs
        queue="default"
        tab="pending"
        view="overview"
        summary={{
          ...summary,
          completedJobs: 250,
          completedComplete: false,
        }}
        activity={{
          data: [],
          total: 0,
          complete: true,
          available: true,
          warming: false,
          message: null,
        }}
        horizonBaseUrl="/horizon"
        querySignature="pending-default"
        {...refreshProps}
      />,
    );

    expect(screen.getByRole("tab", { name: "Completed Jobs 250+" })).toBeVisible();
  });
});
