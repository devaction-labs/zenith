import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import JobsIndex from "@/pages/jobs/index";
import type { JobRow, JobsPageProps } from "@/types/jobs";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn(), reload: vi.fn() }));
const jobTableProps = vi.hoisted(() => ({ current: null as unknown }));
const abilities = vi.hoisted(() => ({ cancelJobs: true }));

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");
  const mocks = inertiaTestMocks({ props: { horizon: { abilities } as never } });

  return { ...mocks, router: { ...mocks.router, ...inertia } };
});

vi.mock("@/hooks/use-job-filter-catalog-refresh", () => ({
  useJobFilterCatalogRefresh: () => vi.fn(),
}));

vi.mock("@/components/jobs/job-table", () => ({
  JobTable: (props: { selection?: { onSelectIds: (ids: readonly string[]) => void } }) => {
    jobTableProps.current = props;

    return props.selection ? (
      <button
        type="button"
        onClick={() => props.selection?.onSelectIds(["pending-1", "pending-2"])}
      >
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
    status: "pending",
    tags: [],
    attempts: 0,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000,
    reservedAt: null,
    completedAt: null,
    failedAt: null,
    runtime: null,
    occurredAt: 1_784_281_000,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: false,
  };
}

function props(): JobsPageProps {
  return {
    horizon: { baseUrl: "/horizon", pollInterval: 0, status: "running" },
    type: "pending",
    query: "",
    pendingCounts: null,
    filters: { job: null, queue: null, connection: null, state: null },
    querySignature: "signature-1",
    listRevision: "revision-1",
    jobs: {
      data: [job("pending-1"), job("pending-2")],
      total: 2,
      available: true,
      message: null,
    },
  };
}

describe("JobsIndex selection", () => {
  it("passes selection wiring down to JobTable", () => {
    render(<JobsIndex {...props()} />);

    const selection = (jobTableProps.current as { selection: { label: string } }).selection;
    expect(selection.label).toBe("pending jobs");
  });

  it("dispatches the cancel-selected request with the chosen ids and clears afterward", () => {
    render(<JobsIndex {...props()} />);

    fireEvent.click(screen.getByRole("button", { name: "Select rows" }));

    expect(screen.getByText("2 jobs selected")).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "Cancel selected" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/jobs/pending/cancel-selected",
      expect.objectContaining({ data: { ids: ["pending-1", "pending-2"] } }),
    );
  });
});
