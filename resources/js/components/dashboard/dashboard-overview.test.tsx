import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { DashboardOverview } from "@/components/dashboard/dashboard-overview";
import { TooltipProvider } from "@/components/ui/tooltip";
import type { DashboardSummary } from "@/types/dashboard";

const summary: DashboardSummary = {
  available: true,
  status: "running",
  failedJobs: 6,
  completedJobs: 120,
  pendingJobs: 12,
  pendingReserved: 2,
  pendingReadyNow: 7,
  pendingDelayed: 3,
  failedJobsPastHour: 6,
  failedJobsPastDay: 18,
  recentlyFailedJobs: 5,
  recentlyFailedPeriodMinutes: 60,
  jobsPerMinute: 1.25,
  recentJobs: 75,
  recentJobsPeriodMinutes: 60,
  processedSinceSnapshot: 982,
  silencedJobs: 4,
  completedRetentionMinutes: 60,
  batchesAvailable: true,
  activeBatches: 3,
  batchPreviews: [
    { id: "batch-3", name: "Archive audit logs", progress: 48 },
    { id: "batch-2", name: "Import order CSV", progress: 13 },
    { id: "batch-1", name: "Reindex product catalog", progress: 72 },
  ],
  processes: 8,
  waits: { "redis:default": 2 },
  maxWaitQueue: "redis:default",
  maxWaitSeconds: 2,
  queueWithMaxRuntime: "exports",
  queueWithMaxThroughput: "default",
  message: null,
};

const links = {
  pending: "/horizon/jobs/pending",
  failed: "/horizon/failed",
  completed: "/horizon/jobs/completed",
  batches: "/horizon/batches",
  batch: (id: string) => `/horizon/batches/${id}`,
};

describe("DashboardOverview", () => {
  it("renders the approved retained job populations and rolling statistics", () => {
    render(
      <TooltipProvider>
        <DashboardOverview summary={summary} links={links} />
      </TooltipProvider>,
    );

    const pending = screen.getByRole("link", { name: /Pending Jobs/ });
    const failed = screen.getByRole("link", { name: /Failed Jobs/ });
    const completed = screen.getByRole("link", { name: /Completed Jobs/ });

    expect(pending).toHaveAttribute("href", "/horizon/jobs/pending");
    expect(within(pending).getByText("12")).toBeVisible();
    expect(within(pending).getByText("Reserved")).toBeVisible();
    expect(within(pending).getByText("2")).toBeVisible();
    expect(within(pending).getByText("Ready")).toBeVisible();
    expect(within(pending).getByText("7")).toBeVisible();
    expect(within(pending).getByText("Delayed")).toBeVisible();
    expect(within(pending).getByText("3")).toBeVisible();
    expect(within(failed).getByText("Past hour")).toBeVisible();
    expect(within(failed).getByText("Past 24 hours")).toBeVisible();
    expect(within(failed).getByText("Past Hour")).toBeVisible();
    expect(failed).toHaveTextContent("6");
    expect(failed).toHaveTextContent("18");
    expect(failed).toHaveTextContent("5");
    expect(within(completed).getByText("Jobs per minute")).toBeVisible();
    expect(within(completed).getByText("1.25")).toBeVisible();
    expect(within(completed).getByText("Throughput")).toBeVisible();
    expect(within(completed).getByText("982")).toBeVisible();
    expect(within(completed).getByText("Silenced Jobs")).toBeVisible();
    expect(within(completed).getByText("4")).toBeVisible();

    expect(screen.getByRole("link", { name: /Archive audit logs/ })).toHaveAttribute(
      "href",
      "/horizon/batches/batch-3",
    );
    expect(screen.getByRole("link", { name: /Import order CSV/ })).toHaveAttribute(
      "href",
      "/horizon/batches/batch-2",
    );
    expect(screen.getByRole("link", { name: /Reindex product catalog/ })).toHaveAttribute(
      "href",
      "/horizon/batches/batch-1",
    );
    expect(screen.getAllByRole("progressbar")).toHaveLength(3);
  });

  it("does not render batch progress when there are no batch previews", () => {
    render(
      <TooltipProvider>
        <DashboardOverview
          summary={{ ...summary, activeBatches: 0, batchPreviews: [] }}
          links={links}
        />
      </TooltipProvider>,
    );

    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("does not render a retained-batch lower bound as an exact count", () => {
    render(
      <TooltipProvider>
        <DashboardOverview summary={{ ...summary, activeBatches: null }} links={links} />
      </TooltipProvider>,
    );

    const batches = screen.getByRole("link", { name: /Batches in progress/ });

    expect(within(batches).getByText("—")).toBeVisible();
    expect(within(batches).getByText("history incomplete")).toBeVisible();
  });

  it("hides the batches overview segment when batch storage is unavailable", () => {
    render(
      <TooltipProvider>
        <DashboardOverview
          summary={{ ...summary, batchesAvailable: false, activeBatches: null, batchPreviews: [] }}
          links={links}
        />
      </TooltipProvider>,
    );

    expect(screen.getByRole("link", { name: /Pending Jobs/ })).toBeVisible();
    expect(screen.getByRole("link", { name: /Failed Jobs/ })).toBeVisible();
    expect(screen.getByRole("link", { name: /Completed Jobs/ })).toBeVisible();
    expect(screen.queryByRole("link", { name: /Batches in progress/ })).not.toBeInTheDocument();
  });

  it("surfaces summary failures", () => {
    render(
      <TooltipProvider>
        <DashboardOverview
          summary={{
            ...summary,
            available: false,
            status: "unavailable",
            message: "Horizon data is currently unavailable.",
          }}
          links={links}
        />
      </TooltipProvider>,
    );

    expect(screen.getByRole("alert")).toHaveTextContent("Horizon data is currently unavailable.");
  });
});
