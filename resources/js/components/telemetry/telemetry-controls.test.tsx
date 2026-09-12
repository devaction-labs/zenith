import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import {
  TelemetryGroupBySelect,
  TelemetryWindowSelect,
} from "@/components/telemetry/telemetry-controls";

describe("TelemetryGroupBySelect", () => {
  it("shows the selected group-by label", () => {
    render(<TelemetryGroupBySelect value="queue" onValueChange={vi.fn()} />);

    expect(screen.getByText("Group by")).toBeVisible();
    expect(screen.getByRole("combobox", { name: "Group by" })).toHaveTextContent("Queue");
  });
});

describe("TelemetryWindowSelect", () => {
  it("shows the selected window label", () => {
    render(<TelemetryWindowSelect value="24h" onValueChange={vi.fn()} />);

    expect(screen.getByText("Window")).toBeVisible();
    expect(screen.getByRole("combobox", { name: "Window" })).toHaveTextContent(
      "Last 24 hours",
    );
  });
});
