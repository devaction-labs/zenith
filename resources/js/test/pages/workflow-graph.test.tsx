import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { WorkflowGraph } from "@/pages/workflows/workflow-graph";
import type { WorkflowStep } from "@/types/workflows";

function step(overrides: Partial<WorkflowStep> & { name: string }): WorkflowStep {
  return {
    jobClass: "App\\Jobs\\Example",
    deps: [],
    cascade: false,
    status: "completed",
    output: null,
    error: null,
    attempts: 1,
    finishedAt: null,
    nested: false,
    childId: null,
    ...overrides,
  };
}

describe("WorkflowGraph", () => {
  it("renders an empty state when there are no steps", () => {
    render(<WorkflowGraph steps={[]} onSelectStep={vi.fn()} />);

    expect(screen.getByText("No steps recorded.")).toBeVisible();
    expect(screen.queryByRole("img")).toBeNull();
  });

  it("renders every step as a focusable, labelled button", () => {
    render(
      <WorkflowGraph
        steps={[
          step({ name: "fetch", status: "completed" }),
          step({ name: "process", status: "failed", deps: ["fetch"] }),
        ]}
        onSelectStep={vi.fn()}
      />,
    );

    const fetchButton = screen.getByRole("button", { name: "fetch, completed" });
    const processButton = screen.getByRole("button", { name: "process, failed" });

    expect(fetchButton.tagName).toBe("BUTTON");
    expect(processButton.tagName).toBe("BUTTON");
    expect(fetchButton).not.toHaveAttribute("tabindex", "-1");
    expect(screen.getByText("fetch")).toBeVisible();
    expect(screen.getByText("process")).toBeVisible();
  });

  it("calls onSelectStep with the full step when a node is activated", () => {
    const onSelectStep = vi.fn();
    const failed = step({ name: "boom", status: "failed", error: "kaboom", attempts: 2 });

    render(<WorkflowGraph steps={[failed]} onSelectStep={onSelectStep} />);

    fireEvent.click(screen.getByRole("button", { name: "boom, failed" }));

    expect(onSelectStep).toHaveBeenCalledWith(failed);
  });

  it("stays legible for a large fan-out/fan-in workflow", () => {
    const fanOut = Array.from({ length: 48 }, (_, index) =>
      step({ name: `branch-${index}`, deps: ["root"] }),
    );

    render(
      <WorkflowGraph
        steps={[
          step({ name: "root" }),
          ...fanOut,
          step({ name: "join", deps: fanOut.map((branch) => branch.name) }),
        ]}
        onSelectStep={vi.fn()}
      />,
    );

    expect(screen.getAllByRole("button")).toHaveLength(50);
    expect(screen.getByRole("img", { name: "Workflow graph with 50 steps" })).toBeVisible();
  });

  it("marks a cascading step with a cascade badge", () => {
    render(
      <WorkflowGraph steps={[step({ name: "fetch", cascade: true })]} onSelectStep={vi.fn()} />,
    );

    expect(screen.getByText("Cascade")).toBeVisible();
  });
});
