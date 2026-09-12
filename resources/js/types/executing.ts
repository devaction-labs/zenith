export type RunningJob = {
  id: string;
  queue: string;
  jobClass: string;
  node: string;
  supervisor: string | null;
  startedAt: number;
  elapsedSeconds: number;
  timeoutSeconds: number | null;
  overrunning: boolean;
};

export type RunningJobsNodeSummary = {
  node: string;
  count: number;
};

export type ExecutingJobsPage = {
  available: boolean;
  jobs: RunningJob[];
  nodeSummary: RunningJobsNodeSummary[];
  message: string | null;
};

export type ExecutingJobsPageProps = {
  horizon: {
    baseUrl: string;
    pollInterval: number;
  };
  executing: ExecutingJobsPage;
};
