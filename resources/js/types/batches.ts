import type { HorizonStatus } from "@/types/dashboard";
import type { JobRow } from "@/types/jobs";

export type BatchStatus = "pending" | "failures" | "finished" | "cancelled";
export type BatchCreatedRange = "hour" | "day" | "week" | "month";
export type BatchClearScope = "incomplete" | "complete" | "finished" | "cancelled";
export type BatchSort =
  | "name"
  | "totalJobs"
  | "pendingJobs"
  | "failedJobs"
  | "progress"
  | "createdAt";
export type BatchSortDirection = "asc" | "desc";

export type BatchClearCounts = Record<BatchClearScope, number> & {
  available: boolean;
  completeScan: boolean;
  message: string | null;
};

export type BatchFilterCatalog = {
  available: boolean;
  complete: boolean;
  message: string | null;
  queues: string[];
  connections: string[];
};

export type BatchFilterValues = {
  queue: string | null;
  connection: string | null;
  created: BatchCreatedRange | null;
};

export type BatchQueryFilters = BatchFilterValues & {
  status: "all" | BatchStatus;
  sort: BatchSort;
  direction: BatchSortDirection;
};

export type BatchStatusCounts = Record<"all" | BatchStatus, number>;

export type BatchQueryCapability = {
  supported: boolean;
  message: string | null;
  attributionSupported?: boolean;
  attributionMessage?: string | null;
};

export type BatchRow = {
  id: string;
  name: string | null;
  displayName: string;
  connection?: string | null;
  queue?: string | null;
  connectionExplicit?: boolean;
  queueExplicit?: boolean;
  attributionCaptured?: boolean;
  totalJobs: number;
  pendingJobs: number;
  failedJobs: number;
  failedJobAttempts: number;
  processedJobs: number;
  progress: number;
  status: BatchStatus;
  createdAt: number;
  cancelledAt: number | null;
  finishedAt: number | null;
};

export type BatchJobList = {
  total: number;
  rows: JobRow[];
  available: boolean;
  complete: boolean;
  message: string | null;
};

export type BatchJobTab = "pending" | "completed" | "failed";

export type BatchDetail = BatchRow & {
  connection: string | null;
  queue: string | null;
  jobs: Record<BatchJobTab, BatchJobList>;
};

type BatchesHorizon = {
  baseUrl: string;
  pollInterval: number;
  status: HorizonStatus;
};

export type BatchesPageProps = {
  horizon: BatchesHorizon;
  batchesAvailable?: boolean;
  query: string;
  filters?: BatchQueryFilters;
  batchStatusCounts?: BatchStatusCounts;
  batchQueryCapability?: BatchQueryCapability;
  batchClearCounts?: BatchClearCounts;
  batchFilterCatalog?: BatchFilterCatalog;
  listRevision: string;
  batches: {
    data: BatchRow[];
    available: boolean;
    complete: boolean;
    message: string | null;
  };
};

export type BatchDetailPageProps = {
  horizon: BatchesHorizon;
  batch: BatchDetail;
};
