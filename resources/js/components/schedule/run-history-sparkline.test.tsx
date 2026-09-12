import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { RunHistorySparkline } from "@/components/schedule/run-history-sparkline";
import type { ScheduleRun } from "@/types/schedule";

function run(overrides: Partial<ScheduleRun> = {}): ScheduleRun {
  return {
    status: "success",
    startedAt: 1_700_000_000,
    durationMs: 42,
    exitCode: 0,
    outputTail: null,
    ...overrides,
  };
}

describe("RunHistorySparkline", () => {
  it("shows a muted message when there is no history", () => {
    render(<RunHistorySparkline history={[]} />);

    expect(screen.getByText("No runs recorded")).toBeVisible();
    expect(screen.queryByRole("img")).toBeNull();
  });

  it("renders one focusable bar per recorded run", () => {
    render(
      <RunHistorySparkline
        history={[
          run({ status: "success" }),
          run({ status: "failed" }),
          run({ status: "skipped" }),
        ]}
      />,
    );

    const graphic = screen.getByRole("img", { name: "Last 3 runs, most recent last" });
    const bars = graphic.querySelectorAll("[tabindex='0']");

    expect(bars).toHaveLength(3);
  });
});
