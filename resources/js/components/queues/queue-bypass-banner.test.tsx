import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { QueueBypassBanner } from "@/components/queues/queue-bypass-banner";
import type { QueueBypassWarning } from "@/types/dashboard";

const quiet: QueueBypassWarning = {
  hasRecentFailovers: false,
  recentFailoverCount: 0,
  recentFailoverWindowMinutes: 60,
  recentFailoverConnections: [],
  bypassProneConnections: [],
};

describe("QueueBypassBanner", () => {
  it("renders nothing when there is no recent failover and no bypass-prone connection", () => {
    const { container } = render(<QueueBypassBanner warning={quiet} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when no warning prop is passed", () => {
    const { container } = render(<QueueBypassBanner />);

    expect(container).toBeEmptyDOMElement();
  });

  it("shows the recent failover count and affected connections", () => {
    render(
      <QueueBypassBanner
        warning={{
          ...quiet,
          hasRecentFailovers: true,
          recentFailoverCount: 3,
          recentFailoverWindowMinutes: 60,
          recentFailoverConnections: ["redis"],
        }}
      />,
    );

    expect(screen.getByText("Jobs may be bypassing Horizon")).toBeVisible();
    expect(screen.getByText(/3 jobs/)).toBeVisible();
    expect(screen.getByText(/redis/)).toBeVisible();
  });

  it("lists connections configured with a bypass-prone driver", () => {
    render(
      <QueueBypassBanner
        warning={{
          ...quiet,
          bypassProneConnections: ["legacy-failover", "offline-tasks"],
        }}
      />,
    );

    expect(screen.getByText(/legacy-failover, offline-tasks/)).toBeVisible();
  });
});
