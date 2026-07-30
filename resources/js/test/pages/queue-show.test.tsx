import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import QueueShow from "@/pages/queues/show";
import type { QueueShowPageProps, QueueSummary } from "@/types/queues";

const useAutoLoad = vi.hoisted(() => vi.fn());
const usePageRefresh = vi.hoisted(() => vi.fn());
const queueActivityTabs = vi.hoisted(() => vi.fn());
const queueOverview = vi.hoisted(() => vi.fn());
const deferredState = vi.hoisted(() => ({ pending: new Set<string>() }));

vi.mock("@inertiajs/react", () => ({
  Deferred: ({
    children,
    data,
    fallback,
  }: {
    children: ReactNode;
    data: string | string[];
    fallback: ReactNode;
  }) => {
    const props = Array.isArray(data) ? data : [data];

    return props.some((prop) => deferredState.pending.has(prop)) ? fallback : children;
  },
  Head: () => null,
}));
vi.mock("@/components/queues/queue-activity-tabs", () => ({
  QueueActivityTabs: (componentProps: unknown) => {
    queueActivityTabs(componentProps);

    return null;
  },
}));
vi.mock("@/components/queues/queue-overview", () => ({
  QueueOverview: (componentProps: unknown) => {
    queueOverview(componentProps);

    return null;
  },
}));
vi.mock("@/hooks/use-auto-load", () => ({ useAutoLoad }));
vi.mock("@/hooks/use-dashboard-refresh", () => ({ usePageRefresh }));
vi.mock("@/layouts/horizon-layout", () => ({
  useAutoLoadPreference: () => ({ autoLoad: true }),
}));

function queueSummary(
  overrides: Partial<QueueSummary> & { completedAvailable?: boolean } = {},
): QueueSummary {
  return {
    available: true,
    retainedJobsWarming: false,
    completedAvailable: true,
    completedJobs: 36,
    completedComplete: true,
    completedJobsPerMinute: 0.6,
    completedJobsPerMinuteComplete: true,
    jobsPerMinute: 1.5,
    completedJobsPastHour: 36,
    completedJobsPastHourComplete: true,
    completedJobsPastDay: 36,
    completedJobsPastDayComplete: true,
    ...overrides,
  } as QueueSummary;
}

const props = {
  horizon: { baseUrl: "/horizon", pollInterval: 5_000 },
  queue: "default",
  view: "overview",
  tab: "pending",
  summary: queueSummary(),
  preview: {},
  activity: {
    data: [],
    total: 0,
    complete: true,
    available: true,
    message: null,
    warming: false,
  },
  querySignature: "default:pending",
  listRevision: "[0,null]",
  batchAttributionAvailable: false,
} as unknown as QueueShowPageProps;

function receivedSummary(component: ReturnType<typeof vi.fn>): QueueSummary {
  return component.mock.lastCall?.[0].summary as QueueSummary;
}

describe("queue detail refresh ownership", () => {
  beforeEach(() => {
    deferredState.pending.clear();
    useAutoLoad.mockReset();
    usePageRefresh.mockReset();
    queueActivityTabs.mockReset();
    queueOverview.mockReset();
    useAutoLoad.mockImplementation(() => ({
      hasNewEntries: false,
      loadNewEntries: vi.fn(),
      onBeforeNextPage: vi.fn(),
    }));
  });

  it("renders the overview while the activity page is still loading", () => {
    deferredState.pending.add("activity");
    deferredState.pending.add("listRevision");

    render(
      <QueueShow
        {...({
          ...props,
          activity: undefined,
          listRevision: undefined,
        } as QueueShowPageProps)}
      />,
    );

    expect(queueOverview).toHaveBeenCalledOnce();
    expect(queueActivityTabs).not.toHaveBeenCalled();
    expect(useAutoLoad).not.toHaveBeenCalled();
    expect(screen.getByRole("status", { name: "Loading queue activity" })).toBeVisible();
    expect(screen.queryByRole("status", { name: "Loading queue overview" })).toBeNull();
  });

  it("renders activity rows while the queue summary is still loading", () => {
    deferredState.pending.add("summary");

    render(<QueueShow {...props} summary={undefined} />);

    expect(queueOverview).toHaveBeenCalledOnce();
    expect(queueActivityTabs).toHaveBeenCalledOnce();
    expect(queueOverview.mock.lastCall?.[0]).toMatchObject({
      summary: {
        available: true,
        name: "default",
        pendingJobs: null,
        completedJobs: null,
        failedJobs: null,
        silencedJobs: null,
        batches: null,
        message: null,
      },
    });
    expect(queueActivityTabs.mock.lastCall?.[0]).toMatchObject({
      activity: props.activity,
      summary: {
        available: true,
        name: "default",
        pendingJobs: null,
        completedJobs: null,
        failedJobs: null,
        silencedJobs: null,
        batches: null,
        message: null,
      },
    });
    expect(useAutoLoad).toHaveBeenCalledOnce();
    expect(screen.queryByRole("status", { name: "Loading queue overview" })).toBeNull();
    expect(screen.queryByRole("status", { name: "Loading queue activity" })).toBeNull();
  });

  it("passes the metric preview directly into the overview panel", () => {
    const preview = {
      available: true,
      data: [{ timestamp: 1, throughput: 2, runtime: 3 }],
      message: null,
    };

    render(<QueueShow {...props} view="metrics" preview={preview} />);

    expect(queueOverview).toHaveBeenCalledOnce();
    expect(queueOverview.mock.lastCall?.[0]).toMatchObject({
      view: "metrics",
      preview,
    });
    expect(queueActivityTabs).toHaveBeenCalledOnce();
    expect(screen.queryByRole("status", { name: "Loading queue overview" })).toBeNull();
    expect(screen.queryByRole("status", { name: "Loading queue activity" })).toBeNull();
  });

  it("does not wait for an unused metric preview in the overview view", () => {
    render(<QueueShow {...props} preview={undefined} />);

    expect(queueOverview).toHaveBeenCalledOnce();
    expect(queueActivityTabs).toHaveBeenCalledOnce();
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("refreshes queue activity through the shared authoritative-list policy", () => {
    const { rerender } = render(<QueueShow {...props} />);

    expect(useAutoLoad).toHaveBeenLastCalledWith({
      enabled: true,
      prop: "activity",
      interval: 5_000,
      cursor: "starting_at",
      listRevision: "[0,null]",
      scope: "default:pending:overview",
      includeSharedProps: false,
      loadedItemCount: 0,
    });
    expect(usePageRefresh).toHaveBeenLastCalledWith(5_000, ["summary"], true);

    rerender(<QueueShow {...props} view="metrics" />);

    expect(useAutoLoad).toHaveBeenLastCalledWith({
      enabled: true,
      prop: "activity",
      interval: 5_000,
      cursor: "starting_at",
      listRevision: "[0,null]",
      scope: "default:pending:metrics",
      includeSharedProps: false,
      loadedItemCount: 0,
    });
    expect(usePageRefresh).toHaveBeenLastCalledWith(5_000, ["summary", "preview"], true);
  });

  it("normalizes an index-warming activity page into a neutral retained result", () => {
    render(
      <QueueShow
        {...props}
        activity={{
          ...props.activity!,
          available: false,
          message: "The retained job index is currently warming.",
          warming: true,
        }}
      />,
    );

    expect(queueActivityTabs.mock.lastCall?.[0].activity).toMatchObject({
      available: true,
      message: null,
      warming: true,
    });
  });

  it("clears the batch cursor before refreshing queue batch activity", () => {
    render(<QueueShow {...props} tab="batches" querySignature="default:batches" />);

    expect(useAutoLoad).toHaveBeenLastCalledWith(
      expect.objectContaining({
        prop: "activity",
        cursor: "before_id",
        scope: "default:batches:overview",
      }),
    );
  });

  it("keeps the last successful completed slice through a failed poll and replaces it on recovery", () => {
    const { rerender } = render(<QueueShow {...props} />);

    rerender(
      <QueueShow
        {...props}
        summary={queueSummary({
          completedAvailable: false,
          completedJobs: null,
          completedComplete: false,
          completedJobsPerMinute: null,
          completedJobsPerMinuteComplete: false,
          completedJobsPastHour: null,
          completedJobsPastHourComplete: false,
          completedJobsPastDay: null,
          completedJobsPastDayComplete: false,
        })}
      />,
    );

    for (const component of [queueOverview, queueActivityTabs]) {
      expect(receivedSummary(component)).toMatchObject({
        completedJobs: 36,
        completedComplete: true,
        completedJobsPerMinute: 0.6,
        completedJobsPerMinuteComplete: true,
        completedJobsPastHour: 36,
        completedJobsPastHourComplete: true,
        completedJobsPastDay: 36,
        completedJobsPastDayComplete: true,
      });
    }

    rerender(
      <QueueShow
        {...props}
        summary={queueSummary({
          completedJobs: 37,
          completedJobsPerMinute: 0.62,
          completedJobsPastHour: 37,
          completedJobsPastDay: 37,
        })}
      />,
    );

    for (const component of [queueOverview, queueActivityTabs]) {
      expect(receivedSummary(component)).toMatchObject({
        completedJobs: 37,
        completedComplete: true,
        completedJobsPerMinute: 0.62,
        completedJobsPastHour: 37,
        completedJobsPastDay: 37,
      });
    }
  });

  it("uses an unavailable completed slice instead of inventing an initial lower-bound zero", () => {
    render(
      <QueueShow
        {...props}
        summary={queueSummary({
          completedAvailable: false,
          completedJobs: 0,
          completedComplete: false,
          completedJobsPerMinute: 0,
          completedJobsPerMinuteComplete: false,
          completedJobsPastHour: 0,
          completedJobsPastHourComplete: false,
          completedJobsPastDay: 0,
          completedJobsPastDayComplete: false,
        })}
      />,
    );

    for (const component of [queueOverview, queueActivityTabs]) {
      expect(receivedSummary(component)).toMatchObject({
        completedJobs: null,
        completedComplete: false,
        completedJobsPerMinute: null,
        completedJobsPerMinuteComplete: false,
        completedJobsPastHour: null,
        completedJobsPastHourComplete: false,
        completedJobsPastDay: null,
        completedJobsPastDayComplete: false,
      });
    }
  });

  it("does not carry a completed slice into another queue", () => {
    const { rerender } = render(<QueueShow {...props} />);
    const unavailable = queueSummary({
      completedAvailable: false,
      completedJobs: null,
      completedComplete: false,
    });

    rerender(
      <QueueShow
        {...props}
        queue="reports"
        querySignature="reports:pending"
        summary={unavailable}
      />,
    );

    expect(receivedSummary(queueOverview).completedJobs).toBeNull();
    expect(receivedSummary(queueActivityTabs).completedJobs).toBeNull();

    rerender(<QueueShow {...props} summary={unavailable} />);

    expect(receivedSummary(queueOverview).completedJobs).toBeNull();
    expect(receivedSummary(queueActivityTabs).completedJobs).toBeNull();
  });

  it("clears a completed slice when the whole queue summary becomes unavailable", () => {
    const { rerender } = render(<QueueShow {...props} />);

    rerender(
      <QueueShow
        {...props}
        summary={queueSummary({
          available: false,
          completedAvailable: false,
          completedJobs: null,
          completedComplete: false,
        })}
      />,
    );

    expect(receivedSummary(queueOverview).completedJobs).toBeNull();
    expect(queueActivityTabs).toHaveBeenCalledOnce();

    rerender(
      <QueueShow
        {...props}
        summary={queueSummary({
          completedAvailable: false,
          completedJobs: null,
          completedComplete: false,
        })}
      />,
    );

    expect(receivedSummary(queueOverview).completedJobs).toBeNull();
    expect(receivedSummary(queueActivityTabs).completedJobs).toBeNull();
  });

  it("does not render stale activity beneath an unavailable queue", () => {
    render(
      <QueueShow
        {...props}
        summary={queueSummary({
          available: false,
          message: "This queue is no longer supervised by Horizon.",
        })}
      />,
    );

    expect(queueOverview).toHaveBeenCalledOnce();
    expect(queueActivityTabs).not.toHaveBeenCalled();
  });
});
