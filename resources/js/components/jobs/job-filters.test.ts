import { describe, expect, it } from "vite-plus/test";

import { pendingJobState } from "@/lib/pending-job-state";
import type { JobRow } from "@/types/jobs";

const scheduledJob: JobRow = {
  id: "job-scheduled",
  index: 0,
  name: "App\\Jobs\\ScheduledJob",
  shortName: "ScheduledJob",
  connection: "redis",
  queue: "default",
  status: "pending",
  tags: [],
  attempts: 0,
  retryOf: null,
  delay: 60,
  scheduledAt: 1_100,
  originalScheduledAt: 1_100,
  pushedAt: 1_000,
  reservedAt: null,
  completedAt: null,
  failedAt: null,
  runtime: null,
  occurredAt: 1_000,
  retried: false,
  retryCompleted: false,
  retryCount: 0,
  latestRetryStatus: null,
  retryEligible: false,
};

describe("pendingJobState", () => {
  it("distinguishes delayed, released, and never-scheduled ready jobs", () => {
    expect(pendingJobState(scheduledJob, 1_099)).toBe("delayed");
    expect(pendingJobState(scheduledJob, 1_100)).toBe("released");
    expect(pendingJobState({ ...scheduledJob, scheduledAt: null }, 1_100)).toBe("ready");
  });
});
