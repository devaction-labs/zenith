export type WorkflowStatus =
  | "pending"
  | "running"
  | "retrying"
  | "completed"
  | "failed"
  | "cancelled"
  | "dispatched";

export type WorkflowStep = {
  name: string;
  jobClass: string;
  deps: string[];
  cascade: boolean;
  status: string;
  output: Record<string, unknown> | null;
  error: string | null;
  attempts: number;
  finishedAt: number | null;
};

export type WorkflowRow = {
  id: string;
  name: string | null;
  status: string;
  stepCount: number;
  completedSteps: number;
  createdAt: number | null;
  finishedAt: number | null;
};

export type WorkflowDetail = {
  id: string;
  name: string | null;
  status: string;
  context: Record<string, unknown>;
  steps: WorkflowStep[];
  createdAt: number | null;
  finishedAt: number | null;
  cancellable: boolean;
  retryable: boolean;
};

export type WorkflowsPageProps = {
  available: boolean;
  workflows: WorkflowRow[];
};

export type WorkflowDetailPageProps = {
  workflow: WorkflowDetail;
};
