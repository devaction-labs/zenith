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
};

export type SchedulePageProps = {
  events: ScheduleEvent[];
  canRun: boolean;
};
