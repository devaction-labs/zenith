import type { HorizonStatus } from "@/types/dashboard";

export type HorizonNavigation =
  | "dashboard"
  | "instances"
  | "queues"
  | "monitoring"
  | "metrics"
  | "batches"
  | "pending"
  | "completed"
  | "silenced"
  | "failed"
  | "audit"
  | "schedule"
  | "workflows";

export type NavigationCounts = {
  instances: number | null;
  monitoring: number | null;
  metrics: number | null;
  queues: number | null;
  batches: number | null;
  pending: number | null;
  completed: number | null;
  silenced: number | null;
  failed: number | null;
};

export type HorizonPageProps = {
  [key: string]: unknown;
  horizon: {
    baseUrl: string;
    pollInterval: number;
    status: HorizonStatus;
    processing: boolean;
    maintenanceMode: boolean;
    jobNavigationBreakdown?: boolean;
    allQueuesPaused?: boolean;
    capabilities?: {
      queuePausing: boolean;
      timedQueuePausing: boolean;
      queuePausingAll?: boolean;
    };
    abilities?: {
      pauseQueues: boolean;
      clearQueues: boolean;
      retryJobs: boolean;
      cancelJobs: boolean;
      manageInstances: boolean;
      manageMonitoring: boolean;
      manageBatches: boolean;
      manageSchedule?: boolean;
      manageWorkflows?: boolean;
    };
  };
  flash?: {
    success?: string | null;
    error?: string | null;
  };
  monitoredTags?: string[];
  navigationCounts?: NavigationCounts;
  meta: {
    title: string;
    activeNavigation: HorizonNavigation;
  };
};
