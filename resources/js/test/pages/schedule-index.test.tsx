import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import ScheduleIndex from "@/pages/schedule/index";
import type { HorizonPageProps } from "@/types/page";
import type { ScheduleEvent, SchedulePageProps } from "@/types/schedule";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
const pageProps = vi.hoisted(() => ({ current: {} as { horizon?: unknown } }));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: inertia,
  usePage: () => ({ props: pageProps.current }),
}));
vi.mock("@/hooks/use-dashboard-refresh", () => ({ usePageRefresh: vi.fn() }));
vi.mock("@/layouts/horizon-layout", () => ({
  useAutoLoadPreference: () => ({ autoLoad: false }),
}));

function event(overrides: Partial<ScheduleEvent> = {}): ScheduleEvent {
  return {
    id: "event-1",
    expression: "0 * * * *",
    description: "Inspire",
    command: "inspire",
    timezone: "UTC",
    nextRunAt: 1_700_000_000,
    withoutOverlapping: false,
    onOneServer: false,
    evenInMaintenanceMode: false,
    runInBackground: false,
    overlapping: false,
    runtimeEditable: false,
    paused: false,
    history: [],
    ...overrides,
  };
}

function horizonProps(
  overrides: Partial<HorizonPageProps["horizon"]> = {},
): HorizonPageProps["horizon"] {
  return {
    baseUrl: "/horizon",
    pollInterval: 5_000,
    status: "running",
    processing: false,
    maintenanceMode: false,
    schedulePaused: false,
    abilities: {
      pauseQueues: true,
      clearQueues: true,
      retryJobs: true,
      cancelJobs: true,
      manageInstances: true,
      manageMonitoring: true,
      manageBatches: true,
      manageSchedule: true,
      manageWorkflows: true,
    },
    ...overrides,
  };
}

describe("ScheduleIndex", () => {
  it("shows a run-history sparkline for each event", () => {
    pageProps.current = { horizon: horizonProps() };

    render(
      <ScheduleIndex
        {...({
          horizon: horizonProps(),
          events: [
            event({
              history: [
                {
                  status: "success",
                  startedAt: 1_700_000_000,
                  durationMs: 10,
                  exitCode: 0,
                  outputTail: null,
                },
              ],
            }),
          ],
          canRun: true,
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    expect(screen.getByRole("img", { name: "Last 1 runs, most recent last" })).toBeVisible();
  });

  it("shows a pause control that posts to the pause route when the scheduler is running", () => {
    pageProps.current = { horizon: horizonProps() };

    render(
      <ScheduleIndex
        {...({
          horizon: horizonProps(),
          events: [event()],
          canRun: true,
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Pause scheduler" }));

    expect(inertia.post).toHaveBeenCalledWith("/horizon/schedule/pause", {}, expect.anything());
  });

  it("shows a resume control that deletes the pause route when the scheduler is paused", () => {
    pageProps.current = { horizon: horizonProps({ schedulePaused: true }) };

    render(
      <ScheduleIndex
        {...({
          horizon: horizonProps({ schedulePaused: true }),
          events: [event()],
          canRun: true,
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Resume scheduler" }));

    expect(inertia.delete).toHaveBeenCalledWith("/horizon/schedule/pause", expect.anything());
  });

  it("hides the pause control without the manageSchedule ability", () => {
    const withoutAbility = horizonProps({
      abilities: {
        pauseQueues: true,
        clearQueues: true,
        retryJobs: true,
        cancelJobs: true,
        manageInstances: true,
        manageMonitoring: true,
        manageBatches: true,
        manageSchedule: false,
        manageWorkflows: true,
      },
    });
    pageProps.current = { horizon: withoutAbility };

    render(
      <ScheduleIndex
        {...({
          horizon: withoutAbility,
          events: [event()],
          canRun: false,
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    expect(screen.queryByRole("button", { name: "Pause scheduler" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Resume scheduler" })).toBeNull();
  });
});
