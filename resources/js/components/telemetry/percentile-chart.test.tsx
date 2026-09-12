import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import {
  formatPercentileMilliseconds,
  formatPercentileTooltipLabel,
  PercentileChart,
} from "@/components/telemetry/percentile-chart";

const points = [
  { timestamp: 1_784_387_100, p50: 50, p95: 400, p99: 5_000 },
  { timestamp: 1_784_387_160, p50: 60, p95: 420, p99: 5_500 },
];

describe("PercentileChart", () => {
  it("formats the chart timestamp instead of the series label", () => {
    const timestamp = 1_784_387_100;

    expect(formatPercentileTooltipLabel("p50", [{ payload: { timestamp } }])).toBe(
      new Intl.DateTimeFormat(undefined, {
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(timestamp * 1000)),
    );
  });

  it("formats millisecond values with the abbreviated duration suffix", () => {
    expect(formatPercentileMilliseconds(10)).toBe("10ms");
    expect(formatPercentileMilliseconds(1_000)).toBe("1s");
  });

  it("renders an accessible shadcn percentile chart with p50, p95, and p99 lines", () => {
    const bounds = vi
      .spyOn(HTMLElement.prototype, "getBoundingClientRect")
      .mockImplementation(function (this: HTMLElement) {
        return this.classList.contains("recharts-legend-wrapper")
          ? new DOMRect(0, 0, 960, 24)
          : new DOMRect(0, 0, 960, 256);
      });
    const { container } = render(<PercentileChart points={points} />);

    expect(screen.getByRole("img", { name: "Execution time percentiles chart" })).toBeVisible();
    expect(screen.getByRole("img", { name: "Execution time percentiles chart" })).toHaveAttribute(
      "data-animation",
      "disabled",
    );
    expect(container.querySelectorAll(".recharts-line")).toHaveLength(3);

    bounds.mockRestore();
  });

  it("uses the shadcn empty state when no percentile has been recorded", () => {
    render(
      <PercentileChart points={[{ timestamp: 1_784_387_100, p50: null, p95: null, p99: null }]} />,
    );

    expect(screen.getByText("Not Enough Data")).toBeVisible();
    expect(
      screen.getByText("No execution time has been recorded for this window yet."),
    ).toBeVisible();
    expect(document.querySelector('[data-slot="empty-icon"] svg')).toHaveClass(
      "lucide-chart-no-axes-combined",
    );
  });
});
