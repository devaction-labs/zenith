import type { HorizonStatus } from "@/types/dashboard";

export type JobListType = "pending" | "completed" | "silenced";
export type JobFilterKey = "job" | "queue" | "connection" | "state" | "tag";
export type JobSort = "name" | "pushedAt" | "completedAt" | "failedAt" | "runtime";

export type JobFilterValues = Record<JobFilterKey, string | null>;

export type JobFilterCatalog = {
  available: boolean;
  jobs: Array<{ value: string; label: string }>;
  queues: string[];
  connections: string[];
  message: string | null;
};

export type JobRow = {
  id: string;
  index: number;
  name: string;
  shortName: string;
  connection: string;
  queue: string;
  status: string;
  tags: string[];
  attempts: number;
  attemptsComplete?: boolean;
  retryOf: string | null;
  delay: number | null;
  scheduledAt: number | null;
  originalScheduledAt: number | null;
  pushedAt: number | null;
  reservedAt: number | null;
  completedAt: number | null;
  failedAt: number | null;
  runtime: number | null;
  occurredAt: number | null;
  retried: boolean;
  retryCompleted: boolean;
  retryCount: number;
  latestRetryStatus: string | null;
  retryEligible: boolean;
  /** When false, the row has no Horizon job hash and must not link to job detail. */
  inspectable?: boolean;
};

export type JobTableSelection = {
  label: string;
  selectedIds: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onSelectIds: (ids: readonly string[]) => void;
  onClear: () => void;
};

export type JobCollection = {
  data: JobRow[];
  total: number;
  available: boolean;
  message: string | null;
};

export type JobComposition = {
  unique: boolean;
  encrypted: boolean;
  chain: Array<{ class: string }>;
};

export type JobAttempt = {
  attempt: number;
  outcome: string;
  exceptionClass: string | null;
  message: string | null;
  fingerprint: string | null;
  runtimeMilliseconds: number | null;
  node: string;
  occurredAt: number;
};

export type AttemptTimeline = {
  available: boolean;
  attempts: JobAttempt[];
  message: string | null;
};

export type JobAttributes = {
  tries: number | null;
  backoff: number | number[] | null;
  timeout: number | null;
  failOnTimeout: boolean;
  maxExceptions: number | null;
  uniqueFor: number | null;
  debounceFor: number | null;
  debounceMaxWait: number | null;
  queue: string | null;
  connection: string | null;
  delay: number | null;
  withoutRelations: boolean;
  deleteWhenMissingModels: boolean;
  routedQueue: string | null;
  routedConnection: string | null;
};

export type JobDetail = Omit<
  JobRow,
  | "index"
  | "occurredAt"
  | "retried"
  | "retryCompleted"
  | "retryCount"
  | "latestRetryStatus"
  | "attemptsComplete"
> & {
  batchId: string | null;
  payload: Record<string, unknown>;
  attemptTimeline: AttemptTimeline;
  composition?: JobComposition;
  attributes?: JobAttributes;
};

export type FailedJobRetry = {
  id: string;
  status: string;
  retriedAt: number | null;
};

export type FailedJobDetail = Omit<JobDetail, "completedAt"> & {
  retried: boolean;
  retriedBy: FailedJobRetry[];
  retryEligible: boolean;
  context: Record<string, unknown> | unknown[];
  exception: string;
};

export type FailedJobBulkActions = {
  hasFailedJobs: boolean;
  retryable: boolean;
  retryUnavailableReason: string | null;
  clearable: boolean;
  clearUnavailableReason: string | null;
};

export type FailedJobsPageProps = {
  horizon: JobsPageProps["horizon"];
  query: string;
  filters: JobFilterValues;
  filterCatalog?: JobFilterCatalog;
  querySignature: string;
  listRevision: string;
  actions: FailedJobBulkActions;
  jobs: JobCollection;
};

export type FailedJobDetailPageProps = {
  horizon: JobsPageProps["horizon"];
  job: FailedJobDetail;
};

export type JobsPageProps = {
  horizon: {
    baseUrl: string;
    pollInterval: number;
    status: HorizonStatus;
  };
  type: JobListType;
  query: string;
  pendingCounts: {
    available: boolean;
    ready: number | null;
    delayed: number | null;
  } | null;
  filters: JobFilterValues;
  filterCatalog?: JobFilterCatalog;
  querySignature: string;
  listRevision: string;
  jobs: JobCollection;
};

export type JobDetailPageProps = {
  horizon: JobsPageProps["horizon"];
  type: JobListType;
  job: JobDetail;
};
