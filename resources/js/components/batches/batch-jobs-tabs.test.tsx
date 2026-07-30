import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { BatchJobsTabs } from "@/components/batches/batch-jobs-tabs";
import type { BatchJobList } from "@/types/batches";
import type { JobRow } from "@/types/jobs";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

vi.mock("@/components/batches/batch-actions", () => ({
  BatchFailedJobsActions: () => null,
}));

function job(
  id: string,
  shortName: string,
  status: "pending" | "completed",
  index: number,
): JobRow {
  return {
    id,
    index,
    name: `App\\Jobs\\${shortName}`,
    shortName,
    connection: "redis",
    queue: "imports",
    status,
    tags: [],
    attempts: 1,
    retryOf: null,
    delay: null,
    scheduledAt: null,
    originalScheduledAt: null,
    pushedAt: 1_784_281_000 + index,
    reservedAt: status === "completed" ? 1_784_281_001 + index : null,
    completedAt: status === "completed" ? 1_784_281_002 + index : null,
    failedAt: null,
    runtime: status === "completed" ? index + 1 : null,
    occurredAt: 1_784_281_002 + index,
    retried: false,
    retryCompleted: false,
    retryCount: 0,
    latestRetryStatus: null,
    retryEligible: false,
  };
}

function jobLists(status: "pending" | "completed", complete: boolean) {
  const rows = [
    job(`${status}-slow`, "SlowJob", status, 2),
    job(`${status}-fast`, "FastJob", status, 1),
  ];
  const empty: BatchJobList = {
    total: 0,
    rows: [],
    available: true,
    complete: true,
    message: null,
  };

  return {
    pending: status === "pending" ? { ...empty, total: rows.length, rows, complete } : empty,
    completed: status === "completed" ? { ...empty, total: rows.length, rows, complete } : empty,
    failed: empty,
  };
}

function renderTabs(status: "pending" | "completed", complete: boolean) {
  return render(
    <BatchJobsTabs
      value={status}
      onValueChange={vi.fn()}
      jobs={jobLists(status, complete)}
      horizonBaseUrl="/horizon"
      batchId="batch-1"
    />,
  );
}

describe("BatchJobsTabs", () => {
  beforeEach(() => {
    window.history.replaceState({}, "", "/horizon/batches/batch-1?tab=completed");
  });

  it("shows the latest completed batch jobs first by default", () => {
    renderTabs("completed", true);

    const rows = within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row");

    expect(rows[0]).toHaveTextContent("SlowJob");
    expect(rows[1]).toHaveTextContent("FastJob");
    expect(screen.getByRole("columnheader", { name: /Completed/ })).toHaveAttribute(
      "aria-sort",
      "descending",
    );
  });

  it.each(["pending", "completed"] as const)(
    "sorts a fully materialized %s list locally",
    (status) => {
      renderTabs(status, true);

      fireEvent.click(screen.getByRole("button", { name: "Sort by Job ascending" }));

      const rows = within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row");

      expect(rows[0]).toHaveTextContent("FastJob");
      expect(rows[1]).toHaveTextContent("SlowJob");
    },
  );

  it.each(["pending", "completed"] as const)(
    "preserves source order and plain headers for an incomplete %s list",
    (status) => {
      renderTabs(status, false);

      expect(screen.queryByRole("button", { name: /Sort by/ })).not.toBeInTheDocument();

      const rows = within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row");

      expect(rows[0]).toHaveTextContent("SlowJob");
      expect(rows[1]).toHaveTextContent("FastJob");
    },
  );
});
