import type { WorkflowStep } from "@/types/workflows";

export const GRAPH_NODE_WIDTH = 160;
export const GRAPH_NODE_HEIGHT = 52;
export const GRAPH_LAYER_GAP = 56;
export const GRAPH_ROW_GAP = 14;

export type WorkflowGraphPoint = {
  x: number;
  y: number;
};

export type WorkflowGraphNode = {
  step: WorkflowStep;
  layer: number;
  row: number;
  x: number;
  y: number;
};

export type WorkflowGraphEdge = {
  id: string;
  from: string;
  to: string;
  start: WorkflowGraphPoint;
  end: WorkflowGraphPoint;
};

export type WorkflowGraphLayout = {
  nodes: WorkflowGraphNode[];
  edges: WorkflowGraphEdge[];
  width: number;
  height: number;
};

/**
 * Assign every step to the layer one past the deepest dependency it has among
 * the steps present in the workflow, so independent branches share a layer and
 * fan back in once their dependents require every branch. A dependency naming
 * a step outside the workflow is ignored, and a cycle (which a well-formed
 * workflow never produces) is broken by pinning the offending step to layer 0
 * instead of recursing forever.
 */
function stepLayers(steps: WorkflowStep[]): Map<string, number> {
  const stepsByName = new Map(steps.map((step) => [step.name, step]));
  const layers = new Map<string, number>();
  const resolving = new Set<string>();

  function layerOf(name: string): number {
    const cached = layers.get(name);

    if (cached !== undefined) {
      return cached;
    }

    if (resolving.has(name)) {
      layers.set(name, 0);

      return 0;
    }

    const step = stepsByName.get(name);

    if (!step || step.deps.length === 0) {
      layers.set(name, 0);

      return 0;
    }

    resolving.add(name);

    let deepestDependencyLayer = -1;

    for (const dependency of step.deps) {
      if (!stepsByName.has(dependency)) {
        continue;
      }

      deepestDependencyLayer = Math.max(deepestDependencyLayer, layerOf(dependency));
    }

    resolving.delete(name);

    const layer = deepestDependencyLayer + 1;
    layers.set(name, layer);

    return layer;
  }

  for (const step of steps) {
    layerOf(step.name);
  }

  return layers;
}

/**
 * Lay every step out in topological layers (left to right) with parallel
 * branches stacked and centered within each layer (top to bottom), then draw
 * a dependency edge from each step's right edge to its dependent's left edge.
 */
export function layoutWorkflowGraph(steps: WorkflowStep[]): WorkflowGraphLayout {
  if (steps.length === 0) {
    return { nodes: [], edges: [], width: 0, height: 0 };
  }

  const layers = stepLayers(steps);
  const stepsByLayer = new Map<number, WorkflowStep[]>();
  let maxLayer = 0;

  for (const step of steps) {
    const layer = layers.get(step.name) ?? 0;
    maxLayer = Math.max(maxLayer, layer);
    const bucket = stepsByLayer.get(layer);

    if (bucket) {
      bucket.push(step);
    } else {
      stepsByLayer.set(layer, [step]);
    }
  }

  const maxRows = Math.max(1, ...Array.from(stepsByLayer.values(), (bucket) => bucket.length));
  const height = maxRows * GRAPH_NODE_HEIGHT + (maxRows - 1) * GRAPH_ROW_GAP;

  const nodes: WorkflowGraphNode[] = [];
  const positionByName = new Map<string, WorkflowGraphPoint>();

  for (let layer = 0; layer <= maxLayer; layer++) {
    const bucket = stepsByLayer.get(layer) ?? [];
    const layerHeight =
      bucket.length * GRAPH_NODE_HEIGHT + Math.max(0, bucket.length - 1) * GRAPH_ROW_GAP;
    const offsetY = (height - layerHeight) / 2;
    const x = layer * (GRAPH_NODE_WIDTH + GRAPH_LAYER_GAP);

    bucket.forEach((step, row) => {
      const y = offsetY + row * (GRAPH_NODE_HEIGHT + GRAPH_ROW_GAP);

      nodes.push({ step, layer, row, x, y });
      positionByName.set(step.name, { x, y });
    });
  }

  const edges: WorkflowGraphEdge[] = [];

  for (const step of steps) {
    const target = positionByName.get(step.name);

    if (!target) {
      continue;
    }

    for (const dependency of step.deps) {
      const source = positionByName.get(dependency);

      if (!source) {
        continue;
      }

      edges.push({
        id: `${dependency}->${step.name}`,
        from: dependency,
        to: step.name,
        start: { x: source.x + GRAPH_NODE_WIDTH, y: source.y + GRAPH_NODE_HEIGHT / 2 },
        end: { x: target.x, y: target.y + GRAPH_NODE_HEIGHT / 2 },
      });
    }
  }

  const width = (maxLayer + 1) * GRAPH_NODE_WIDTH + maxLayer * GRAPH_LAYER_GAP;

  return { nodes, edges, width, height };
}

/**
 * A smooth cubic Bezier from a step's right edge to its dependent's left edge,
 * bowing through the horizontal midpoint so edges between non-adjacent rows
 * do not overlap node bodies.
 */
export function workflowGraphEdgePath(edge: WorkflowGraphEdge): string {
  const midX = (edge.start.x + edge.end.x) / 2;

  return `M ${edge.start.x} ${edge.start.y} C ${midX} ${edge.start.y}, ${midX} ${edge.end.y}, ${edge.end.x} ${edge.end.y}`;
}
