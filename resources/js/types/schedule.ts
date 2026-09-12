export type ScheduleRunStatus = "success" | "failed" | "skipped";

export type ScheduleRun = {
  status: ScheduleRunStatus;
  startedAt: number;
  durationMs: number | null;
  exitCode: number | null;
  outputTail: string | null;
};

export type ScheduleEvent = {
  id: string;
  expression: string;
  description: string;
  command: string | null;
  timezone: string | null;
  nextRunAt: number | null;
  withoutOverlapping: boolean;
  onOneServer: boolean;
  evenInMaintenanceMode: boolean;
  runInBackground: boolean;
  overlapping: boolean;
  runtimeEditable: boolean;
  paused: boolean;
  history: ScheduleRun[];
  dynamicCronId: number | null;
  payload: Record<string, unknown> | null;
};

export type SchedulePageProps = {
  events: ScheduleEvent[];
  canRun: boolean;
  dynamicCronAllowedClasses: string[];
};
