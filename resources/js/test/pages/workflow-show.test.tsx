import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import WorkflowShow from "@/pages/workflows/show";
import type { HorizonPageProps } from "@/types/page";
import type { WorkflowDetail, WorkflowDetailPageProps, WorkflowStep } from "@/types/workflows";

const usePageRefresh = vi.hoisted(() => vi.fn());
const pageProps = vi.hoisted(() => ({ current: {} as { horizon?: unknown } }));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
  router: { post: vi.fn() },
  usePage: () => ({ props: pageProps.current }),
}));
vi.mock("@/hooks/use-dashboard-refresh", () => ({ usePageRefresh }));
vi.mock("@/layouts/horizon-layout", () => ({
  useAutoLoadPreference: () => ({ autoLoad: false }),
}));

function step(overrides: Partial<WorkflowStep> & { name: string }): WorkflowStep {
  return {
    jobClass: "App\\Jobs\\Example",
    deps: [],
    cascade: false,
    status: "completed",
    output: null,
    error: null,
    attempts: 1,
    finishedAt: null,
    nested: false,
    childId: null,
    ...overrides,
  };
}

function workflow(overrides: Partial<WorkflowDetail> = {}): WorkflowDetail {
  return {
    id: "wf-1",
    name: "report",
    status: "completed",
    context: {},
    steps: [step({ name: "fetch" })],
    createdAt: 1_700_000_000,
    finishedAt: 1_700_000_100,
    cancellable: false,
    retryable: false,
    parentId: null,
    children: [],
    ...overrides,
  };
}

const horizonProps: HorizonPageProps["horizon"] = {
  baseUrl: "/horizon",
  pollInterval: 5_000,
  status: "running",
  processing: false,
  maintenanceMode: false,
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
};

pageProps.current = { horizon: horizonProps };

describe("WorkflowShow", () => {
  it("shows the graph tab by default with the table available as a fallback", () => {
    render(
      <WorkflowShow
        {...({ horizon: horizonProps } as HorizonPageProps)}
        {...({ workflow: workflow() } as WorkflowDetailPageProps)}
      />,
    );

    expect(screen.getByRole("img", { name: "Workflow graph with 1 steps" })).toBeVisible();
    expect(screen.queryByRole("table")).toBeNull();

    fireEvent.click(screen.getByRole("tab", { name: "Table" }));

    const table = screen.getByRole("table");
    expect(within(table).getByText("fetch")).toBeVisible();
  });

  it("opens a step detail panel with output when a graph node is activated", () => {
    render(
      <WorkflowShow
        {...({ horizon: horizonProps } as HorizonPageProps)}
        {...({
          workflow: workflow({
            steps: [step({ name: "fetch", output: { items: [1, 2] } })],
          }),
        } as WorkflowDetailPageProps)}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "fetch, completed" }));

    expect(screen.getByRole("dialog")).toBeVisible();
    expect(screen.getByText('"items"')).toBeVisible();
  });

  it("shows the step error instead of output when the step failed", () => {
    render(
      <WorkflowShow
        {...({ horizon: horizonProps } as HorizonPageProps)}
        {...({
          workflow: workflow({
            status: "failed",
            steps: [step({ name: "boom", status: "failed", error: "kaboom" })],
          }),
        } as WorkflowDetailPageProps)}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "boom, failed" }));

    expect(screen.getByText("kaboom")).toBeVisible();
  });
});
