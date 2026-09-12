import { Badge } from "@/components/ui/badge";
import {
  GRAPH_NODE_HEIGHT,
  GRAPH_NODE_WIDTH,
  layoutWorkflowGraph,
  workflowGraphEdgePath,
} from "@/pages/workflows/dag-layout";
import { workflowStatusVariant } from "@/pages/workflows/status";
import type { WorkflowStep } from "@/types/workflows";

const ARROW_MARKER_ID = "workflow-graph-arrow";

export function WorkflowGraph({
  steps,
  onSelectStep,
}: {
  steps: WorkflowStep[];
  onSelectStep: (step: WorkflowStep) => void;
}) {
  if (steps.length === 0) {
    return (
      <p className="px-4 py-8 text-center text-sm text-muted-foreground sm:px-6">
        No steps recorded.
      </p>
    );
  }

  const layout = layoutWorkflowGraph(steps);
  const padding = 16;

  return (
    <div className="max-h-[32rem] overflow-auto p-4">
      <svg
        role="img"
        aria-label={`Workflow graph with ${steps.length} steps`}
        width={layout.width + padding * 2}
        height={layout.height + padding * 2}
        viewBox={`${-padding} ${-padding} ${layout.width + padding * 2} ${layout.height + padding * 2}`}
      >
        <defs>
          <marker
            id={ARROW_MARKER_ID}
            markerWidth="8"
            markerHeight="8"
            refX="7"
            refY="4"
            orient="auto"
            markerUnits="userSpaceOnUse"
          >
            <path d="M0,0 L8,4 L0,8 Z" className="fill-muted-foreground" />
          </marker>
        </defs>
        <g>
          {layout.edges.map((edge) => (
            <path
              key={edge.id}
              d={workflowGraphEdgePath(edge)}
              className="fill-none stroke-border"
              strokeWidth={1.5}
              markerEnd={`url(#${ARROW_MARKER_ID})`}
            />
          ))}
        </g>
        {layout.nodes.map((node) => (
          <foreignObject
            key={node.step.name}
            x={node.x}
            y={node.y}
            width={GRAPH_NODE_WIDTH}
            height={GRAPH_NODE_HEIGHT}
          >
            <button
              type="button"
              onClick={() => onSelectStep(node.step)}
              aria-label={`${node.step.name}, ${node.step.status}`}
              className="flex h-full w-full flex-col items-start justify-center gap-1 overflow-hidden rounded-md border border-border bg-card px-2.5 py-1.5 text-left shadow-xs outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="w-full truncate text-xs font-medium text-foreground">
                {node.step.name}
              </span>
              <span className="flex w-full items-center gap-1">
                <Badge
                  variant={workflowStatusVariant(node.step.status)}
                  className="pointer-events-none"
                >
                  {node.step.status}
                </Badge>
                {node.step.cascade ? <Badge className="pointer-events-none">Cascade</Badge> : null}
              </span>
            </button>
          </foreignObject>
        ))}
      </svg>
    </div>
  );
}
