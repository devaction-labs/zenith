import { render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { QueueOverview } from "@/components/queues/queue-overview";
import { TooltipProvider } from "@/components/ui/tooltip";
import type { QueueSummary } from "@/types/queues";

const linkProps = vi.hoisted(() => vi.fn());

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");
  const mocks = inertiaTestMocks();

  return {
    ...mocks,
    Link: ({
      children,
      href,
      ...props
    }: {
      children?: ReactNode;
      href: string;
      [key: string]: unknown;
    }) => {
      linkProps({ href, ...props });

      return <a href={href}>{children}</a>;
    },
  };
});

vi.mock("@/components/queues/queue-actions-menu", () => ({
  QueueActionsMenu: () => null,
  QueuePauseBadge: () => null,
}));

const summary = {
  available: true,
  name: "default",
  connections: ["redis"],
  pauseTargets: [],
  pendingJobs: 10,
  pendingComplete: true,
  retainedJobsWarming: false,
  pendingReserved: 1,
  pendingReadyNow: 9,
  pendingDelayed: 0,
  failedJobs: 0,
  failedComplete: true,
  failedJobsPerMinute: 0,
  failedJobsPerMinuteComplete: true,
  failedJobsPastHour: 0,
  failedJobsPastHourComplete: true,
  failedJobsPastDay: 0,
  failedJobsPastDayComplete: true,
  failedRetentionMinutes: 10_080,
  completedJobs: 20,
  completedComplete: true,
  completedAvailable: true,
  completedJobsPerMinute: 1,
  completedJobsPerMinuteComplete: true,
  completedJobsPastHour: 20,
  completedJobsPastHourComplete: true,
  completedJobsPastDay: 20,
  completedJobsPastDayComplete: true,
  completedRetentionMinutes: 60,
  silencedJobs: 0,
  silencedComplete: true,
  batches: 0,
  activeBatches: 0,
  batchesComplete: true,
  batchPreviews: [],
  processes: 1,
  waitThreshold: null,
  jobsPerMinute: 2,
  throughput: 1,
  averageRuntime: 0.01,
  message: null,
} satisfies QueueSummary;

describe("QueueOverview", () => {
  beforeEach(() => {
    linkProps.mockReset();
  });

  it("uses all available columns when batch attribution is unavailable", () => {
    const { container } = render(
      <TooltipProvider>
        <QueueOverview
          view="overview"
          tab="pending"
          summary={summary}
          preview={null}
          horizonBaseUrl="/horizon"
          batchAttributionAvailable={false}
        />
      </TooltipProvider>,
    );

    expect(container.querySelector(".md\\:grid-cols-3")).toBeInTheDocument();
    expect(container.querySelector(".md\\:grid-cols-4")).toBeInTheDocument();
  });

  it("aligns completed-job details with the dashboard snapshot metrics contract", () => {
    render(
      <TooltipProvider>
        <QueueOverview
          view="overview"
          tab="pending"
          summary={summary}
          preview={null}
          horizonBaseUrl="/horizon"
          batchAttributionAvailable
        />
      </TooltipProvider>,
    );

    const completed = screen.getByRole("link", { name: /Completed Jobs/ });

    expect(within(completed).getByText("Jobs per minute")).toBeVisible();
    expect(within(completed).getByText("2")).toBeVisible();
    expect(within(completed).getByText("Throughput")).toBeVisible();
    expect(within(completed).getByText("Silenced Jobs")).toBeVisible();
    expect(within(completed).queryByText(/Jobs Past/)).not.toBeInTheDocument();

    expect(screen.getByText("Average Runtime")).toBeVisible();
    expect(screen.getByText("Total Processes")).toBeVisible();
    expect(screen.queryByText("Since last snapshot")).not.toBeInTheDocument();
    expect(screen.queryByText("in progress")).not.toBeInTheDocument();
  });

  it("prefetches only the props needed to switch queue views", () => {
    render(
      <TooltipProvider>
        <QueueOverview
          view="overview"
          tab="pending"
          summary={summary}
          preview={null}
          horizonBaseUrl="/horizon"
          batchAttributionAvailable={false}
        />
      </TooltipProvider>,
    );

    const metricsLink = linkProps.mock.calls
      .map(([props]) => props)
      .find(({ href }) => String(href).includes("view=metrics"));

    expect(metricsLink).toMatchObject({
      only: ["view", "preview"],
      prefetch: true,
      preserveState: true,
    });
  });
});
