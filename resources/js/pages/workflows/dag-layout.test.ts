import { describe, expect, it } from "vite-plus/test";

import {
  GRAPH_NODE_HEIGHT,
  GRAPH_NODE_WIDTH,
  layoutWorkflowGraph,
  workflowGraphEdgePath,
} from "@/pages/workflows/dag-layout";
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

describe("layoutWorkflowGraph", () => {
  it("returns an empty layout for a workflow with no steps", () => {
    const layout = layoutWorkflowGraph([]);

    expect(layout).toEqual({ nodes: [], edges: [], width: 0, height: 0 });
  });

  it("places a step with no dependencies in the first layer", () => {
    const layout = layoutWorkflowGraph([step({ name: "fetch" })]);

    expect(layout.nodes).toHaveLength(1);
    expect(layout.nodes[0]).toMatchObject({ layer: 0, row: 0, x: 0, y: 0 });
    expect(layout.width).toBe(GRAPH_NODE_WIDTH);
    expect(layout.height).toBe(GRAPH_NODE_HEIGHT);
  });

  it("advances a dependent step to the next layer and draws an edge between them", () => {
    const layout = layoutWorkflowGraph([
      step({ name: "fetch" }),
      step({ name: "process", deps: ["fetch"] }),
    ]);

    const fetchNode = layout.nodes.find((node) => node.step.name === "fetch");
    const processNode = layout.nodes.find((node) => node.step.name === "process");

    expect(fetchNode?.layer).toBe(0);
    expect(processNode?.layer).toBe(1);
    expect(layout.edges).toHaveLength(1);
    expect(layout.edges[0]).toMatchObject({ from: "fetch", to: "process" });
  });

  it("keeps a step at the layer past its deepest dependency, not merely one past any dependency", () => {
    const layout = layoutWorkflowGraph([
      step({ name: "a" }),
      step({ name: "b", deps: ["a"] }),
      step({ name: "c", deps: ["b"] }),
      step({ name: "join", deps: ["a", "c"] }),
    ]);

    const layerOf = (name: string) => layout.nodes.find((node) => node.step.name === name)?.layer;

    expect(layerOf("a")).toBe(0);
    expect(layerOf("b")).toBe(1);
    expect(layerOf("c")).toBe(2);
    expect(layerOf("join")).toBe(3);
  });

  it("ignores a dependency that does not name a step in the workflow", () => {
    const layout = layoutWorkflowGraph([step({ name: "solo", deps: ["missing"] })]);

    expect(layout.nodes[0]).toMatchObject({ layer: 0 });
    expect(layout.edges).toEqual([]);
  });

  it("breaks a dependency cycle instead of recursing forever", () => {
    const layout = layoutWorkflowGraph([
      step({ name: "a", deps: ["b"] }),
      step({ name: "b", deps: ["a"] }),
    ]);

    expect(layout.nodes).toHaveLength(2);
    expect(layout.nodes.every((node) => Number.isFinite(node.layer))).toBe(true);
  });

  it("stacks and centers parallel branches within a fan-out layer without overlapping rows", () => {
    const fanOut = Array.from({ length: 48 }, (_, index) =>
      step({ name: `branch-${index}`, deps: ["root"] }),
    );
    const layout = layoutWorkflowGraph([
      step({ name: "root" }),
      ...fanOut,
      step({ name: "join", deps: fanOut.map((branch) => branch.name) }),
    ]);

    const branchNodes = layout.nodes.filter((node) => node.step.name.startsWith("branch-"));
    expect(branchNodes).toHaveLength(48);

    const rowYs = branchNodes.map((node) => node.y).sort((a, b) => a - b);
    for (let index = 1; index < rowYs.length; index++) {
      expect(rowYs[index]).toBeGreaterThan(rowYs[index - 1]);
    }

    const uniqueYs = new Set(rowYs);
    expect(uniqueYs.size).toBe(rowYs.length);

    const rootNode = layout.nodes.find((node) => node.step.name === "root");
    const joinNode = layout.nodes.find((node) => node.step.name === "join");
    expect(rootNode?.layer).toBe(0);
    expect(joinNode?.layer).toBe(2);

    expect(layout.edges).toHaveLength(48 * 2);
    expect(layout.height).toBeGreaterThan(GRAPH_NODE_HEIGHT * 47);
  });

  it("builds a smooth cubic Bezier path between an edge's endpoints", () => {
    const path = workflowGraphEdgePath({
      id: "a->b",
      from: "a",
      to: "b",
      start: { x: 0, y: 10 },
      end: { x: 100, y: 40 },
    });

    expect(path).toBe("M 0 10 C 50 10, 50 40, 100 40");
  });
});
