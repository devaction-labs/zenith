import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { BatchTable } from "@/components/batches/batch-table";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

const batches = [
  {
    id: "batch-slow",
    name: "Slow export",
    displayName: "Slow export",
    totalJobs: 500,
    pendingJobs: 25,
    failedJobs: 3,
    failedJobAttempts: 3,
    processedJobs: 475,
    progress: 95,
    status: "failures" as const,
    createdAt: 1_784_281_000,
    cancelledAt: null,
    finishedAt: null,
  },
  {
    id: "batch-pending",
    name: "Pending import",
    displayName: "Pending import",
    totalJobs: 80,
    pendingJobs: 80,
    failedJobs: 0,
    failedJobAttempts: 0,
    processedJobs: 0,
    progress: 0,
    status: "pending" as const,
    createdAt: 1_784_279_000,
    cancelledAt: null,
    finishedAt: null,
  },
  {
    id: "batch-fast",
    name: null,
    displayName: "batch-fast",
    totalJobs: 12,
    pendingJobs: 0,
    failedJobs: 0,
    failedJobAttempts: 0,
    processedJobs: 12,
    progress: 100,
    status: "finished" as const,
    createdAt: 1_784_282_000,
    cancelledAt: null,
    finishedAt: 1_784_282_100,
  },
  {
    id: "batch-cancelled",
    name: "Cancelled import",
    displayName: "Cancelled import",
    totalJobs: 150,
    pendingJobs: 40,
    failedJobs: 2,
    failedJobAttempts: 2,
    processedJobs: 110,
    progress: 73,
    status: "cancelled" as const,
    createdAt: 1_784_280_000,
    cancelledAt: 1_784_280_500,
    finishedAt: null,
  },
];

describe("BatchTable", () => {
  it("renders supplied source order without advertising loaded-row sorting", () => {
    render(<BatchTable batches={batches} horizonBaseUrl="/horizon" />);

    expect(screen.getAllByRole("columnheader")).toHaveLength(5);
    expect(screen.getByRole("link", { name: "Slow export" })).toHaveAttribute(
      "href",
      "/horizon/batches/batch-slow",
    );
    expect(screen.getByText("Cancelled")).toBeVisible();
    expect(screen.getByText("Complete")).toBeVisible();

    const rows = within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row");

    expect(screen.queryByRole("button", { name: /Sort by/ })).not.toBeInTheDocument();
    expect(rows[0]).toHaveTextContent("Slow export");
    expect(rows[3]).toHaveTextContent("Cancelled import");
  });

  it("delegates explicitly controlled source sorting without reordering rows", () => {
    const onSort = vi.fn();

    render(
      <BatchTable
        batches={batches}
        horizonBaseUrl="/horizon"
        sorting={{
          key: "totalJobs",
          direction: "asc",
          columns: ["totalJobs"],
          onSort,
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Total descending" }));

    expect(onSort).toHaveBeenCalledWith("totalJobs");
    expect(within(screen.getAllByRole("rowgroup")[1]).getAllByRole("row")[0]).toHaveTextContent(
      "Slow export",
    );
  });

  it("only renders the handoff's cancelled status pill", () => {
    render(<BatchTable batches={batches} horizonBaseUrl="/horizon" />);

    expect(screen.queryByText("Pending")).not.toBeInTheDocument();
    expect(screen.queryByText("Failures")).not.toBeInTheDocument();
    expect(screen.queryByText("Finished")).not.toBeInTheDocument();
    expect(screen.getByText("Cancelled")).toBeVisible();
  });

  it("matches the completed icon size to the progress ring", () => {
    render(<BatchTable batches={batches} horizonBaseUrl="/horizon" />);

    const completedProgress = screen
      .getAllByRole("progressbar")
      .find((progress) => progress.getAttribute("aria-valuenow") === "100");

    expect(completedProgress?.querySelector("svg")).toHaveClass("size-4");
  });

  it("renders the shared visible empty state when there are no batches", () => {
    render(<BatchTable batches={[]} horizonBaseUrl="/horizon" />);

    const emptyRow = screen.getByRole("row", { name: "No batches" });

    expect(emptyRow.querySelector('[data-slot="empty"]')).toBeVisible();
    expect(emptyRow.querySelector('[data-slot="empty-icon"] svg')).toHaveClass("lucide-layers3");
    expect(screen.getByText("No batches")).toBeVisible();
    expect(screen.getByText("There aren't any batches.")).toBeVisible();
  });

  it("does not qualify available table rows with a retained-history warning", () => {
    render(<BatchTable batches={batches} horizonBaseUrl="/horizon" />);

    expect(screen.queryByText("Retained history is incomplete")).not.toBeInTheDocument();
  });
});
