import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { WorkloadTable } from "@/components/dashboard/workload-table";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

describe("WorkloadTable", () => {
  it("renders Horizon workload rows", () => {
    render(
      <WorkloadTable
        horizonBaseUrl="/horizon"
        workload={{
          available: true,
          message: null,
          items: [
            {
              name: "default",
              connection: "redis",
              length: 12,
              wait: 3,
              processes: 4,
              processesShared: false,
              throughput: 8,
              paused: false,
              pausedUntil: null,
              splitQueues: null,
            },
          ],
        }}
      />,
    );

    expect(screen.getByRole("cell", { name: "default" })).toBeVisible();
    expect(screen.getByRole("cell", { name: "12" })).toBeVisible();
    expect(screen.getByRole("cell", { name: "3s" })).toBeVisible();
    expect(screen.getByRole("cell", { name: "4" })).toBeVisible();
    expect(screen.getByRole("cell", { name: "8" })).toBeVisible();
  });

  it("distinguishes an empty queue from unavailable data", () => {
    const { rerender } = render(
      <WorkloadTable
        horizonBaseUrl="/horizon"
        workload={{ available: true, message: null, items: [] }}
      />,
    );

    expect(screen.getByText("All queues are clear")).toBeVisible();
    expect(document.querySelector('[data-slot="empty-icon"] svg')).toHaveClass(
      "lucide-layout-dashboard",
    );

    rerender(
      <WorkloadTable
        horizonBaseUrl="/horizon"
        workload={{
          available: false,
          message: "Horizon workload is currently unavailable.",
          items: [],
        }}
      />,
    );

    expect(screen.getByText("Horizon workload is currently unavailable.")).toBeVisible();
  });

  it("sorts parent workload groups without detaching split queues", () => {
    render(
      <WorkloadTable
        horizonBaseUrl="/horizon"
        workload={{
          available: true,
          message: null,
          items: [
            {
              name: "zeta",
              connection: "redis",
              length: 2,
              wait: 3600,
              processes: 1,
              processesShared: false,
              throughput: null,
              paused: false,
              pausedUntil: null,
              splitQueues: null,
            },
            {
              name: "alpha,beta",
              connection: "redis",
              length: 10,
              wait: 8,
              processes: 3,
              processesShared: true,
              throughput: 12,
              paused: false,
              pausedUntil: null,
              splitQueues: [
                {
                  name: "alpha",
                  length: 6,
                  wait: 8,
                  paused: false,
                  pausedUntil: null,
                  throughput: 7,
                },
                {
                  name: "beta",
                  length: 4,
                  wait: 3,
                  paused: true,
                  pausedUntil: null,
                  throughput: 5,
                },
              ],
            },
          ],
        }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Sort by Ready Jobs ascending" }));
    const rowGroups = screen.getAllByRole("rowgroup");
    let rows = within(rowGroups[1]).getAllByRole("row");

    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent("zeta");
    expect(rows[0]).toHaveTextContent("1h");
    expect(rows[1]).toHaveTextContent("alpha, beta");
    expect(screen.queryByText("Paused")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Expand shared pool alpha, beta/i }));
    rows = within(rowGroups[1]).getAllByRole("row");

    expect(rows).toHaveLength(4);
    expect(rows[0]).toHaveTextContent("zeta");
    expect(rows[1]).toHaveTextContent("alpha, beta");
    // Split children inherit the parent Ready Jobs ascending sort.
    expect(rows[2]).toHaveTextContent("beta");
    expect(rows[3]).toHaveTextContent("alpha");
    expect(screen.getAllByRole("button", { name: /queue actions/i })).toHaveLength(3);
    expect(screen.getByText("Paused")).toBeVisible();
  });
});
