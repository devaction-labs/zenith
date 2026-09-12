import type { QueueWaitThreshold } from "@/types/queues";
import type { TelemetryGroupBy, TelemetryWindow, ThroughputChart } from "@/types/telemetry";

export type HorizonStatus = "running" | "paused" | "inactive" | "unavailable";
export type HorizonTransitionStatus = "continuing" | "pausing";
export type HorizonDisplayStatus = HorizonStatus | HorizonTransitionStatus;

export type ProcessTransitions = {
  instances: Record<string, HorizonTransitionStatus>;
  supervisors: Record<string, HorizonTransitionStatus>;
};

export type DashboardSummary = {
  available: boolean;
  status: HorizonStatus;
  failedJobs: number;
  completedJobs: number;
  pendingJobs: number;
  pendingReserved: number | null;
  pendingReadyNow: number | null;
  pendingDelayed: number | null;
  failedJobsPastHour: number;
  failedJobsPastDay: number;
  recentlyFailedJobs: number;
  recentlyFailedPeriodMinutes: number;
  jobsPerMinute: number;
  recentJobs: number;
  recentJobsPeriodMinutes: number;
  processedSinceSnapshot: number;
  silencedJobs: number;
  completedRetentionMinutes: number;
  batchesAvailable: boolean;
  activeBatches: number | null;
  batchPreviews: Array<{
    id: string;
    name: string;
    progress: number;
  }>;
  processes: number;
  waits: Record<string, number>;
  maxWaitQueue: string | null;
  maxWaitSeconds: number;
  queueWithMaxRuntime: string | null;
  queueWithMaxThroughput: string | null;
  message: string | null;
  allPaused?: boolean;
};

export type WorkloadSplitQueue = {
  name: string;
  wait: number;
  length: number;
  paused: boolean;
  pausedUntil: number | null;
  throughput: number | null;
  waitThreshold?: QueueWaitThreshold;
};

export type WorkloadItem = {
  name: string;
  connection: string;
  length: number;
  wait: number;
  processes: number;
  processesShared: boolean;
  paused: boolean;
  pausedUntil: number | null;
  throughput: number | null;
  waitThreshold?: QueueWaitThreshold;
  /** Child queues for a comma-delimited balance=false process pool; null for dedicated queues. */
  splitQueues: WorkloadSplitQueue[] | null;
};

export type DashboardWorkload = {
  available: boolean;
  items: WorkloadItem[];
  message: string | null;
};

export type SupervisorItem = {
  id: string;
  name: string;
  connection: string;
  queues: string[];
  processes: number;
  balancing: string;
  status: string;
  scaling?: {
    readyJobs: number;
    state: "up" | "down" | "steady";
    strategy: "time" | "size";
    targetProcesses: number;
  } | null;
};

export type DashboardSupervisors = {
  available: boolean;
  groups: Array<{
    name: string;
    environment?: string | null;
    pid?: number | null;
    status: string;
    local: boolean;
    items: SupervisorItem[];
  }>;
  message: string | null;
};

export type FailurePreview = {
  id: string;
  name: string;
  queue: string;
  failedAt: number;
};

export type RecentFailures = {
  available: boolean;
  items: FailurePreview[];
  message: string | null;
};

export type QueueBypassWarning = {
  hasRecentFailovers: boolean;
  recentFailoverCount: number;
  recentFailoverWindowMinutes: number;
  recentFailoverConnections: string[];
  bypassProneConnections: string[];
};

export type DashboardPageProps = {
  horizon: {
    baseUrl: string;
    pollInterval: number;
    status: HorizonStatus;
    processing?: boolean;
    capabilities?: {
      queuePausing: boolean;
      timedQueuePausing: boolean;
      queuePausingAll?: boolean;
    };
  };
  summary: DashboardSummary;
  workload: DashboardWorkload;
  supervisors?: DashboardSupervisors;
  liveThroughput: ThroughputChart;
  liveMetricsGroupBy: TelemetryGroupBy;
  liveMetricsWindow: TelemetryWindow;
  queueBypassWarning?: QueueBypassWarning;
};

export type RunningInstancesPageProps = {
  horizon: {
    baseUrl: string;
    pollInterval: number;
    status: HorizonStatus;
    processing?: boolean;
    capabilities?: {
      queuePausing: boolean;
      timedQueuePausing: boolean;
      queuePausingAll?: boolean;
    };
  };
  supervisors: DashboardSupervisors;
};
