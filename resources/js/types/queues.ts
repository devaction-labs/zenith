import type { BatchRow } from "@/types/batches";
import type { HorizonStatus } from "@/types/dashboard";
import type { JobRow } from "@/types/jobs";
import type { MetricPreview } from "@/types/metrics";

export type QueueWaitThresholdStatus = "exceeded" | "calculating" | "within_bounds" | "disabled";

export type QueueWaitThresholdTarget = {
  connection: string;
  status: QueueWaitThresholdStatus;
  monitored: boolean;
  waitSeconds: number | null;
  thresholdSeconds: number;
  oldestReadyAgeSeconds: number | null;
};

export type QueueWaitThreshold = {
  status: QueueWaitThresholdStatus;
  decisiveConnection: string;
  waitSeconds: number | null;
  thresholdSeconds: number;
  oldestReadyAgeSeconds: number | null;
  oldestReadyConnection: string | null;
  targets: QueueWaitThresholdTarget[];
};

export type QueueTarget = {
  connection: string;
  paused: boolean;
  pausedUntil: number | null;
  ready: number;
  reserved: number;
  delayed: number;
  total: number;
};

export type QueueRow = {
  name: string;
  connections: string[];
  pauseTargets: QueueTarget[];
  ready: number;
  reserved: number;
  delayed: number;
  processes: number;
  wait: number;
  waitThreshold: QueueWaitThreshold;
};

export type QueueList = {
  available: boolean;
  queues: QueueRow[];
  message: string | null;
  allPaused?: boolean;
};

export type QueueActivityTab = "pending" | "completed" | "failed" | "silenced" | "batches";
export type QueueDetailView = "overview" | "metrics";

export type QueueSummary = {
  available: boolean;
  name: string;
  connections: string[];
  pauseTargets: QueueTarget[];
  pendingJobs: number | null;
  pendingComplete: boolean;
  retainedJobsWarming: boolean;
  pendingReserved: number | null;
  pendingReadyNow: number | null;
  pendingDelayed: number | null;
  failedJobs: number | null;
  failedComplete: boolean;
  failedJobsPerMinute: number | null;
  failedJobsPerMinuteComplete: boolean;
  failedJobsPastHour: number | null;
  failedJobsPastHourComplete: boolean;
  failedJobsPastDay: number | null;
  failedJobsPastDayComplete: boolean;
  failedRetentionMinutes: number;
  completedJobs: number | null;
  completedComplete: boolean;
  completedAvailable: boolean;
  completedJobsPerMinute: number | null;
  completedJobsPerMinuteComplete: boolean;
  completedJobsPastHour: number | null;
  completedJobsPastHourComplete: boolean;
  completedJobsPastDay: number | null;
  completedJobsPastDayComplete: boolean;
  completedRetentionMinutes: number;
  silencedJobs: number | null;
  silencedComplete: boolean;
  batches: number | null;
  activeBatches: number | null;
  batchesComplete: boolean;
  batchPreviews: Array<{
    id: string;
    name: string;
    progress: number;
  }>;
  processes: number | null;
  waitThreshold: QueueWaitThreshold | null;
  /** Projected from queue throughput since Horizon's last metrics snapshot; null when metrics fail. */
  jobsPerMinute: number | null;
  throughput: number | null;
  averageRuntime: number | null;
  message: string | null;
  routing?: {
    available: boolean;
    classRoutes: Array<{ class: string; queue: string; connection: string | null }>;
    forwardedQueue: string | null;
    forwardedConnection: string | null;
  } | null;
};

export type QueueActivity = {
  data: readonly (JobRow | BatchRow)[];
  total: number;
  complete: boolean;
  available: boolean;
  warming: boolean;
  message: string | null;
};

type QueuesHorizon = {
  baseUrl: string;
  pollInterval: number;
  status: HorizonStatus;
  allQueuesPaused?: boolean;
  capabilities?: {
    queuePausing: boolean;
    timedQueuePausing: boolean;
    queuePausingAll?: boolean;
  };
};

export type QueuesPageProps = {
  horizon: QueuesHorizon;
  queues: QueueList;
};

export type QueueShowPageProps = {
  horizon: QueuesHorizon;
  queue: string;
  view: QueueDetailView;
  summary?: QueueSummary;
  tab: QueueActivityTab;
  querySignature: string;
  listRevision?: string;
  preview?: MetricPreview | null;
  activity?: QueueActivity;
  batchAttributionAvailable?: boolean;
};
