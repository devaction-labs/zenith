import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { QueueTable } from "@/components/queues/queue-table";
import type { QueueList, QueueRow } from "@/types/queues";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

const waitThreshold: QueueRow["waitThreshold"] = {
  status: "within_bounds",
  decisiveConnection: "redis",
  waitSeconds: 1,
  thresholdSeconds: 60,
  oldestReadyAgeSeconds: null,
  oldestReadyConnection: null,
  targets: [],
};

function queueRow(overrides: Partial<QueueRow>): QueueRow {
  return {
    name: "reports",
    connections: ["redis"],
    pauseTargets: [],
    ready: 0,
    reserved: 0,
    delayed: 0,
    processes: 1,
    wait: 0,
    waitThreshold,
    ...overrides,
  };
}

function queueList(rows: QueueRow[]): QueueList {
  return { available: true, queues: rows, message: null };
}

describe("QueueTable", () => {
  it("flags a queue whose connection uses a bypass-prone driver", () => {
    render(
      <QueueTable
        queues={queueList([
          queueRow({ name: "reports", connections: ["legacy-failover"] }),
          queueRow({ name: "mail", connections: ["redis"] }),
        ])}
        horizonBaseUrl="/horizon"
        bypassProneConnections={["legacy-failover"]}
      />,
    );

    const bypassBadges = screen.getAllByText("Bypasses Horizon");

    expect(bypassBadges).toHaveLength(1);
  });

  it("shows no bypass badge without a bypass-prone connection", () => {
    render(
      <QueueTable
        queues={queueList([queueRow({ name: "reports", connections: ["redis"] })])}
        horizonBaseUrl="/horizon"
      />,
    );

    expect(screen.queryByText("Bypasses Horizon")).not.toBeInTheDocument();
  });
});
