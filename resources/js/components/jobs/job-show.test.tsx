import { router } from "@inertiajs/react";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vite-plus/test";

import JobShow from "@/pages/jobs/show";
import type { JobDetailPageProps } from "@/types/jobs";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

const props: JobDetailPageProps = {
  horizon: { baseUrl: "/horizon", pollInterval: 5000, status: "running" },
  type: "pending",
  job: {
    id: "job-1",
    name: "App\\Jobs\\ImportFeed",
    shortName: "ImportFeed",
    connection: "redis",
    queue: "imports",
    status: "pending",
    tags: ["tenant:1"],
    attempts: 1,
    retryOf: null,
    delay: 600,
    scheduledAt: 2_000_000_000,
    originalScheduledAt: 2_000_000_000,
    batchId: "batch-42",
    pushedAt: 1_784_281_000,
    reservedAt: null,
    completedAt: null,
    failedAt: null,
    runtime: null,
    retryEligible: false,
    payload: {
      data: {
        commandName: "App\\Jobs\\ImportFeed",
        decodedCommand: { customerId: 42 },
      },
    },
    attemptTimeline: { available: false, attempts: [], message: null },
  },
};

describe("JobShow", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("shows job details and switches between data and tags", () => {
    render(<JobShow {...props} />);

    expect(screen.getByRole("link", { name: "batch-42" })).toHaveAttribute(
      "href",
      "/horizon/batches/batch-42",
    );
    expect(screen.getByText("Scheduled at")).toBeVisible();
    expect(screen.getByText("Created at")).toBeVisible();
    expect(screen.getByText(/customerId/)).toBeVisible();
    fireEvent.click(screen.getByRole("tab", { name: "Tags1" }));
    expect(screen.getByText("tenant:1")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Collapse job details" })).not.toBeInTheDocument();
    expect(screen.getByText("Connection")).toBeVisible();
    expect(screen.getByText("Tries")).toBeVisible();
    expect(screen.queryByText("Attempts")).not.toBeInTheDocument();
    expect(screen.getByText("job-1").closest("dd")).toHaveClass("text-[13px]");
    expect(screen.getByRole("link", { name: "batch-42" })).toHaveClass("text-[13px]");
  });

  it("presents a scheduled pending job as released after its scheduled time", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-23T08:00:00Z"));
    const scheduledAt = Date.now() / 1000 + 5;

    const { rerender } = render(
      <JobShow
        {...props}
        job={{
          ...props.job,
          scheduledAt,
        }}
      />,
    );

    expect(screen.getByRole("status", { name: "Job status: Delayed" })).toBeVisible();

    act(() => {
      vi.advanceTimersByTime(5_001);
    });

    expect(screen.getByRole("status", { name: "Job status: Released" })).toBeVisible();

    rerender(
      <JobShow
        {...props}
        job={{
          ...props.job,
          status: "reserved",
          scheduledAt,
        }}
      />,
    );

    expect(screen.getByRole("status", { name: "Job status: Reserved" })).toBeVisible();
  });

  it("offers the same cancellation action for normal ready and delayed jobs", () => {
    render(
      <JobShow
        {...props}
        job={{
          ...props.job,
          batchId: null,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Pending job actions" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Cancel job" }));

    expect(screen.getByRole("heading", { name: "Cancel this job?" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Cancel job" })).toBeVisible();
  });

  it("does not offer individual cancellation for reserved or batched jobs", () => {
    const { rerender } = render(
      <JobShow
        {...props}
        job={{
          ...props.job,
          batchId: null,
          status: "reserved",
        }}
      />,
    );

    expect(screen.queryByRole("button", { name: "Pending job actions" })).not.toBeInTheDocument();

    rerender(<JobShow {...props} />);

    expect(screen.queryByRole("button", { name: "Pending job actions" })).not.toBeInTheDocument();
  });

  it("offers a retry action for a retry-eligible completed job", () => {
    render(
      <JobShow
        {...props}
        type="completed"
        job={{
          ...props.job,
          status: "completed",
          completedAt: 1_784_281_002,
          retryEligible: true,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Retry job" }));

    expect(router.post).toHaveBeenCalledWith(
      "/horizon/jobs/completed/job-1/retry",
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("offers a retry action for a retry-eligible silenced job", () => {
    render(
      <JobShow
        {...props}
        type="silenced"
        job={{
          ...props.job,
          status: "completed",
          completedAt: 1_784_281_002,
          retryEligible: true,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Retry job" }));

    expect(router.post).toHaveBeenCalledWith(
      "/horizon/jobs/silenced/job-1/retry",
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("hides the retry action for a completed job that is not retry-eligible", () => {
    render(
      <JobShow
        {...props}
        type="completed"
        job={{
          ...props.job,
          status: "completed",
          completedAt: 1_784_281_002,
          retryEligible: false,
        }}
      />,
    );

    expect(screen.queryByRole("button", { name: "Retry job" })).not.toBeInTheDocument();
  });
});
