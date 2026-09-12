import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import FailedJobsIndex from "@/pages/failed-jobs/index";
import type { FailedJobsPageProps, JobRow } from "@/types/jobs";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn(), reload: vi.fn() }));
const abilities = vi.hoisted(() => ({ retryJobs: true, clearQueues: true }));

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");
  const mocks = inertiaTestMocks({ props: { horizon: { abilities } as never } });

  return { ...mocks, router: { ...mocks.router, ...inertia } };
});

vi.mock("@/hooks/use-job-filter-catalog-refresh", () => ({
  useJobFilterCatalogRefresh: () => vi.fn(),
}));

vi.mock("@/components/jobs/failed-job-table", () => ({
  FailedJobTable: (props: { selection?: { onSelectIds: (ids: readonly string[]) => void } }) => {
    return props.selection ? (
      <button type="button" onClick={() => props.selection?.onSelectIds(["failed-1", "failed-2"])}>
        Select rows
      </button>
    ) : null;
  },
}));

function job(id: string): JobRow {
  return {
    id,
    index: 0,
    name: `App\\Jobs\\${id}`,
    shortName: id,
    connection: "redis",
    queue: "default",
    status: "failed",
    tags: [],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000,
    reservedAt: null,
    completedAt: null,
    failedAt: 1_784_281_010,
    runtime: 1,
    occurredAt: 1_784_281_010,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: true,
  };
}

function props(): FailedJobsPageProps {
  return {
    horizon: { baseUrl: "/horizon", pollInterval: 0, status: "running" },
    query: "",
    filters: { job: null, queue: null, connection: null, state: null, tag: null },
    querySignature: "signature-1",
    listRevision: "revision-1",
    actions: {
      hasFailedJobs: true,
      retryable: true,
      retryUnavailableReason: null,
      clearable: true,
      clearUnavailableReason: null,
    },
    jobs: {
      data: [job("failed-1"), job("failed-2")],
      total: 2,
      available: true,
      message: null,
    },
  };
}

describe("FailedJobsIndex selection", () => {
  it("retries the selected failed jobs", () => {
    render(<FailedJobsIndex {...props()} />);

    fireEvent.click(screen.getByRole("button", { name: "Select rows" }));
    expect(screen.getByText("2 jobs selected")).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "Retry selected" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/failed/retry-selected",
      { ids: ["failed-1", "failed-2"] },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("removes the selected failed jobs after confirmation", () => {
    render(<FailedJobsIndex {...props()} />);

    fireEvent.click(screen.getByRole("button", { name: "Select rows" }));
    fireEvent.click(screen.getByRole("button", { name: "Remove selected" }));

    const dialog = screen.getByRole("dialog", { name: "Remove 2 selected failed jobs?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Remove selected" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/failed/selected",
      expect.objectContaining({ data: { ids: ["failed-1", "failed-2"] } }),
    );
  });
});
