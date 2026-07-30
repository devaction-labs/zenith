import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import MonitoringShow from "@/pages/monitoring/show";
import type { JobRow } from "@/types/jobs";
import type { MonitoringTagPageProps } from "@/types/monitoring";

const infiniteScrollProps = vi.hoisted(() => vi.fn());

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");
  const mocks = inertiaTestMocks();

  return {
    ...mocks,
    InfiniteScroll: ({ children, ...props }: { children: ReactNode }) => {
      infiniteScrollProps(props);

      return children;
    },
  };
});

const job = (overrides: Partial<JobRow>): JobRow => ({
  id: "job-1",
  index: 0,
  name: "App\\Jobs\\DelayedExport",
  shortName: "DelayedExport",
  connection: "redis",
  queue: "exports",
  status: "completed",
  tags: ["customer:42"],
  attempts: 1,
  retryOf: null,
  delay: null,
  scheduledAt: null,
  originalScheduledAt: null,
  pushedAt: 1_784_281_000,
  reservedAt: 1_784_281_001,
  completedAt: 1_784_281_002,
  failedAt: null,
  runtime: 1,
  occurredAt: 1_784_281_002,
  retried: false,
  retryCompleted: false,
  retryCount: 0,
  latestRetryStatus: null,
  retryEligible: false,
  ...overrides,
});

const props: MonitoringTagPageProps = {
  horizon: { baseUrl: "/horizon", pollInterval: 0, status: "running" },
  tag: "customer:42",
  status: "jobs",
  listRevision: '[2,"job-1"]',
  summary: {
    tag: "customer:42",
    trackedCount: 2,
    failedCount: 0,
    silenced: false,
    monitoredRetentionMinutes: 10_080,
    failedRetentionMinutes: 10_080,
  },
  jobs: {
    data: [
      job({}),
      job({
        id: "job-2",
        index: 1,
        name: "App\\Jobs\\SendInvoice",
        shortName: "SendInvoice",
        queue: "default",
      }),
    ],
    total: 2,
    available: true,
    message: null,
  },
};

describe("Monitoring tag page query controls", () => {
  beforeEach(() => {
    infiniteScrollProps.mockReset();
    window.history.replaceState(
      {},
      "",
      "/horizon/monitoring/customer%3A42/jobs?starting_at=0&job=App%5CJobs%5CDelayedExport&queue=exports&tab=jobs",
    );
  });

  it("does not present loaded-row filters or sorting as global controls", () => {
    render(<MonitoringShow {...props} />);

    expect(screen.queryByRole("button", { name: /Filter jobs/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Sort by/ })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "DelayedExport" })).toBeVisible();
    expect(screen.getByRole("link", { name: "SendInvoice" })).toBeVisible();
    expect(screen.getByText("Retention: 7d for recent jobs, 7d for failed jobs")).toBeVisible();
    expect(infiniteScrollProps).toHaveBeenLastCalledWith(expect.objectContaining({ buffer: 600 }));
    expect(infiniteScrollProps.mock.lastCall?.[0]).not.toHaveProperty("loading");
  });

  it("does not expose stale query filters when the selected status has no jobs", () => {
    window.history.replaceState(
      {},
      "",
      "/horizon/monitoring/customer%3A42/failed?job=App%5CJobs%5CDelayedExport&queue=exports&tab=failed",
    );

    render(
      <MonitoringShow {...props} status="failed" jobs={{ ...props.jobs, data: [], total: 0 }} />,
    );

    expect(screen.queryByRole("button", { name: /Filter jobs/ })).not.toBeInTheDocument();
    expect(screen.getByRole("row", { name: "No jobs for this tag" })).toBeVisible();
  });
});
