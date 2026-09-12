import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import {
  buildThroughputChartData,
  formatThroughputTooltipLabel,
  seriesDataKey,
  ThroughputChart,
} from "@/components/telemetry/throughput-chart";

const series = [
  {
    label: "Processed",
    points: [
      { timestamp: 1_784_387_100, count: 4 },
      { timestamp: 1_784_387_160, count: 6 },
    ],
  },
  {
    label: "Failed",
    points: [
      { timestamp: 1_784_387_100, count: 1 },
      { timestamp: 1_784_387_160, count: 0 },
    ],
  },
];

describe("ThroughputChart", () => {
  it("keys each series by a slug derived from its label", () => {
    expect(seriesDataKey("Processed")).toBe("processed");
    expect(seriesDataKey("Job class")).toBe("job-class");
  });

  it("formats the chart timestamp instead of the series label", () => {
    const timestamp = 1_784_387_100;

    expect(formatThroughputTooltipLabel("Processed", [{ payload: { timestamp } }])).toBe(
      new Intl.DateTimeFormat(undefined, {
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(timestamp * 1000)),
    );
  });

  it("merges every series onto a shared, sorted timestamp axis", () => {
    const { data, config } = buildThroughputChartData(series);

    expect(data).toEqual([
      { timestamp: 1_784_387_100, processed: 4, failed: 1 },
      { timestamp: 1_784_387_160, processed: 6, failed: 0 },
    ]);
    expect(config.processed?.label).toBe("Processed");
    expect(config.failed?.label).toBe("Failed");
  });

  it("renders an accessible shadcn throughput chart with one line per series", () => {
    const bounds = vi
      .spyOn(HTMLElement.prototype, "getBoundingClientRect")
      .mockImplementation(function (this: HTMLElement) {
        return this.classList.contains("recharts-legend-wrapper")
          ? new DOMRect(0, 0, 960, 24)
          : new DOMRect(0, 0, 960, 256);
      });
    const { container } = render(<ThroughputChart series={series} />);

    expect(screen.getByRole("img", { name: "Live throughput chart" })).toBeVisible();
    expect(screen.getByRole("img", { name: "Live throughput chart" })).toHaveAttribute(
      "data-animation",
      "disabled",
    );
    expect(container.querySelectorAll(".recharts-line")).toHaveLength(2);

    bounds.mockRestore();
  });

  it("uses the shadcn empty state when there is no recorded throughput", () => {
    render(<ThroughputChart series={[]} />);

    expect(screen.getByText("Not Enough Data")).toBeVisible();
    expect(
      screen.getByText("No jobs have been recorded for this window yet."),
    ).toBeVisible();
    expect(document.querySelector('[data-slot="empty-icon"] svg')).toHaveClass(
      "lucide-chart-no-axes-combined",
    );
  });
});
