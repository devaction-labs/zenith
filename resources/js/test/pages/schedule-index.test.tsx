import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it, vi } from "vite-plus/test";

import ScheduleIndex from "@/pages/schedule/index";
import type { HorizonPageProps } from "@/types/page";
import type { ScheduleEvent, SchedulePageProps } from "@/types/schedule";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
const formCalls = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
const pageProps = vi.hoisted(() => ({ current: {} as { horizon?: unknown } }));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: inertia,
  usePage: () => ({ props: pageProps.current }),
  useForm: (initial: Record<string, string>) => {
    const [data, setDataState] = useState(initial);

    return {
      data,
      errors: {},
      processing: false,
      setData: (keyOrData: string | Record<string, string>, value?: string) => {
        if (typeof keyOrData === "string") {
          setDataState((previous) => ({ ...previous, [keyOrData]: value ?? "" }));

          return;
        }

        setDataState(keyOrData);
      },
      clearErrors: () => {},
      post: (url: string, options?: { onSuccess?: () => void }) => {
        formCalls.post(url, data);
        options?.onSuccess?.();
      },
      put: (url: string, options?: { onSuccess?: () => void }) => {
        formCalls.put(url, data);
        options?.onSuccess?.();
      },
    };
  },
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
    dynamicCronId: null,
    payload: null,
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
          dynamicCronAllowedClasses: [],
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
          dynamicCronAllowedClasses: [],
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
          dynamicCronAllowedClasses: [],
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
          dynamicCronAllowedClasses: [],
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    expect(screen.queryByRole("button", { name: "Pause scheduler" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Resume scheduler" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Create dynamic cron" })).toBeNull();
  });

  it("opens the create dialog and posts a new dynamic cron", () => {
    pageProps.current = { horizon: horizonProps() };

    render(
      <ScheduleIndex
        {...({
          horizon: horizonProps(),
          events: [event()],
          canRun: true,
          dynamicCronAllowedClasses: ["App\\Jobs\\Safe"],
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Create dynamic cron" }));

    expect(screen.getByRole("dialog")).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "Create" }));

    expect(formCalls.post).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons",
      expect.anything(),
    );
  });

  it("edits a dynamic cron from its row actions menu", async () => {
    pageProps.current = { horizon: horizonProps() };

    render(
      <ScheduleIndex
        {...({
          horizon: horizonProps(),
          events: [
            event({
              description: "nightly-report",
              runtimeEditable: true,
              dynamicCronId: 5,
            }),
          ],
          canRun: true,
          dynamicCronAllowedClasses: ["App\\Jobs\\Safe"],
        } as unknown as HorizonPageProps & SchedulePageProps)}
      />,
    );

    fireEvent.pointerDown(screen.getByRole("button", { name: "nightly-report cron actions" }), {
      button: 0,
      ctrlKey: false,
    });
    fireEvent.click(await screen.findByRole("menuitem", { name: "Edit" }));

    expect(screen.getByText("Edit dynamic cron")).toBeVisible();
    expect(screen.getByLabelText("Name")).toHaveValue("nightly-report");
  });
});
