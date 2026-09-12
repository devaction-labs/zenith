import { router } from "@inertiajs/react";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vite-plus/test";

import { JobTable } from "@/components/jobs/job-table";
import type { JobRow } from "@/types/jobs";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

const jobs: JobRow[] = [
  {
    id: "job-slow",
    index: 0,
    name: "App\\Jobs\\SlowJob",
    shortName: "SlowJob",
    connection: "redis",
    queue: "default",
    status: "completed",
    tags: ["tenant:1"],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000,
    reservedAt: 1_784_281_001,
    completedAt: 1_784_281_006,
    failedAt: null,
    runtime: 5,
    occurredAt: 1_784_281_006,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: false,
  },
  {
    id: "job-fast",
    index: 1,
    name: "App\\Jobs\\FastJob",
    shortName: "FastJob",
    connection: "redis",
    queue: "mail",
    status: "completed",
    tags: [],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_282_000,
    reservedAt: 1_784_282_001,
    completedAt: 1_784_282_002,
    failedAt: null,
    runtime: 1,
    occurredAt: 1_784_282_002,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: false,
  },
];

describe("JobTable", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("does not advertise loaded-row sorting by default", () => {
    render(<JobTable jobs={jobs} type="completed" horizonBaseUrl="/horizon" />);

    const rows = within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row");

    expect(screen.queryByRole("button", { name: /Sort by/ })).not.toBeInTheDocument();
    expect(rows[0]).toHaveTextContent("SlowJob");
    expect(rows[1]).toHaveTextContent("FastJob");
    expect(screen.getByRole("link", { name: "FastJob" })).toHaveAttribute(
      "href",
      "/horizon/jobs/completed/job-fast",
    );
  });

  it("delegates explicitly controlled source sorting without reordering supplied rows", () => {
    const onSort = vi.fn();

    render(
      <JobTable
        jobs={jobs}
        type="completed"
        horizonBaseUrl="/horizon"
        sorting={{
          key: "completedAt",
          direction: "asc",
          columns: ["completedAt"],
          onSort,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Completed descending" }));

    expect(onSort).toHaveBeenCalledWith("completedAt");
    expect(within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row")[0]).toHaveTextContent(
      "SlowJob",
    );
  });

  it("uses the compact pending-job column set", () => {
    render(
      <JobTable
        jobs={[
          {
            ...jobs[0],
            id: "job-delayed",
            status: "pending",
            delay: 60,
            scheduledAt: 2_000_000_000,
            tags: ["tenant:1", "exports", "priority", "region:eu"],
          },
        ]}
        type="pending"
        horizonBaseUrl="/horizon"
      />,
    );

    expect(screen.getAllByRole("columnheader")).toHaveLength(4);
    expect(screen.getByRole("columnheader", { name: "State" })).toBeVisible();
    expect(screen.queryByRole("columnheader", { name: /runtime/i })).not.toBeInTheDocument();
    expect(screen.getByText("Delayed")).toBeVisible();
    expect(screen.getByText(/\+1 more/)).toBeVisible();
  });

  it("hides individual pending actions and detail links for non-inspectable batch rows", () => {
    render(
      <JobTable
        jobs={[
          {
            ...jobs[0],
            id: "batch-pending",
            status: "pending",
            scheduledAt: null,
            inspectable: false,
          },
        ]}
        type="pending"
        horizonBaseUrl="/horizon"
        showPendingActions={false}
      />,
    );

    expect(screen.queryByRole("columnheader", { name: "Actions" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Pending job actions/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "SlowJob" })).not.toBeInTheDocument();
    expect(screen.getByText("SlowJob")).toBeVisible();
  });

  it("presents a scheduled job as released once its release time passes", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-23T08:00:00Z"));
    const scheduledAt = Date.now() / 1000 + 5;

    const { rerender } = render(
      <JobTable
        jobs={[
          {
            ...jobs[0],
            id: "job-scheduled",
            status: "pending",
            delay: 5,
            scheduledAt,
          },
        ]}
        type="pending"
        horizonBaseUrl="/horizon"
      />,
    );

    expect(screen.getByText("Delayed")).toBeVisible();

    act(() => {
      vi.advanceTimersByTime(5_001);
    });

    expect(screen.getByText("Released")).toBeVisible();
    expect(screen.queryByText("Delayed")).not.toBeInTheDocument();

    rerender(
      <JobTable
        jobs={[
          {
            ...jobs[0],
            id: "job-scheduled",
            status: "reserved",
            delay: 0,
            scheduledAt,
          },
        ]}
        type="pending"
        horizonBaseUrl="/horizon"
      />,
    );

    expect(screen.getByText("Reserved")).toBeVisible();
    expect(screen.queryByText("Released")).not.toBeInTheDocument();
  });

  it("offers to insert entries discovered while auto-load is disabled", () => {
    const loadNewEntries = vi.fn();

    render(
      <JobTable
        jobs={jobs}
        type="completed"
        horizonBaseUrl="/horizon"
        hasNewEntries
        onLoadNewEntries={loadNewEntries}
      />,
    );

    expect(screen.getByText("Results have changed.")).toBeVisible();
    fireEvent.click(screen.getByRole("link", { name: "Reload" }));

    expect(loadNewEntries).toHaveBeenCalledOnce();
  });

  it.each([
    ["pending", "lucide-circle-pause"],
    ["completed", "lucide-circle-check"],
    ["silenced", "lucide-bell-off"],
  ] as const)("uses the %s navigation icon for its empty state", (type, iconName) => {
    render(<JobTable jobs={[]} type={type} horizonBaseUrl="/horizon" />);

    const row = screen.getByRole("row", { name: `No ${type} jobs` });

    expect(row.querySelector('[data-slot="empty-icon"] svg')).toHaveClass(iconName);
  });

  it("adds a checkbox column and reports toggled row selection", () => {
    const onToggle = vi.fn();
    const onSelectIds = vi.fn();
    const onClear = vi.fn();

    render(
      <JobTable
        jobs={jobs}
        type="completed"
        horizonBaseUrl="/horizon"
        selection={{
          label: "completed jobs",
          selectedIds: new Set(["job-slow"]),
          onToggle,
          onSelectIds,
          onClear,
        }}
      />,
    );

    expect(screen.getAllByRole("columnheader")).toHaveLength(5);
    expect(screen.getByRole("checkbox", { name: "Select job SlowJob" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Select job FastJob" })).not.toBeChecked();

    fireEvent.click(screen.getByRole("checkbox", { name: "Select job FastJob" }));
    expect(onToggle).toHaveBeenCalledWith("job-fast");
  });

  it("does not navigate to the job detail page when a row checkbox is clicked", () => {
    render(
      <JobTable
        jobs={jobs}
        type="completed"
        horizonBaseUrl="/horizon"
        selection={{
          label: "completed jobs",
          selectedIds: new Set(),
          onToggle: vi.fn(),
          onSelectIds: vi.fn(),
          onClear: vi.fn(),
        }}
      />,
    );

    fireEvent.click(screen.getByRole("checkbox", { name: "Select job SlowJob" }));

    expect(router.visit).not.toHaveBeenCalled();
  });

  it("excludes reserved pending jobs from selection", () => {
    render(
      <JobTable
        jobs={[
          { ...jobs[0], id: "job-reserved", status: "reserved" },
          { ...jobs[1], id: "job-ready", status: "pending" },
        ]}
        type="pending"
        horizonBaseUrl="/horizon"
        selection={{
          label: "pending jobs",
          selectedIds: new Set(),
          onToggle: vi.fn(),
          onSelectIds: vi.fn(),
          onClear: vi.fn(),
        }}
      />,
    );

    expect(screen.queryByRole("checkbox", { name: "Select job SlowJob" })).not.toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "Select job FastJob" })).toBeInTheDocument();
  });

  it("supports batch-specific empty-state copy", () => {
    render(
      <JobTable
        jobs={[]}
        type="completed"
        horizonBaseUrl="/horizon"
        emptyTitle="No retained completed jobs"
        emptyDescription="Horizon has already trimmed these completed jobs."
      />,
    );

    expect(screen.getByRole("row", { name: "No retained completed jobs" })).toHaveTextContent(
      "Horizon has already trimmed these completed jobs.",
    );
  });
});
