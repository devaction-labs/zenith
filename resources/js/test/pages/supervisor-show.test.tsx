import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vite-plus/test";

import SupervisorShow from "@/pages/supervisors/show";
import type { SupervisorDetailsPageProps } from "@/types/supervisors";

const refresh = vi.hoisted(() => vi.fn());
const inertia = vi.hoisted(() => ({ post: vi.fn() }));
const abilities = vi.hoisted(() => ({ manageInstances: true }));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ children, href }: { children: ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
  router: inertia,
  usePage: () => ({ props: { horizon: { abilities } } }),
}));

vi.mock("@/hooks/use-dashboard-refresh", () => ({
  usePageRefresh: refresh,
}));

vi.mock("@/layouts/horizon-layout", () => ({
  useAutoLoadPreference: () => ({ autoLoad: true }),
}));

const props: SupervisorDetailsPageProps = {
  horizon: {
    baseUrl: "/horizon",
    pollInterval: 5_000,
    status: "running",
    processing: true,
    maintenanceMode: false,
  },
  supervisorDetails: {
    available: true,
    message: null,
    supervisor: {
      id: "local-host-a1b2:supervisor-1",
      name: "supervisor-1",
      master: "local-host-a1b2",
      pid: 42,
      status: "running",
      connection: "redis",
      queues: ["default"],
      processes: 1,
      balance: "auto",
      autoScalingStrategy: "time",
      minProcesses: 1,
      maxProcesses: 5,
      balanceCooldown: 3,
      balanceMaxShift: 1,
      memory: 128,
      timeout: 60,
      retryAfter: 90,
      maxTries: 3,
      backoff: 0,
      maxJobs: 0,
      maxTime: 0,
      sleep: 3,
      rest: 0,
      force: false,
      nice: 0,
      warnings: [],
    },
  },
  supervisorScaleBounds: { min: 1, max: 5 },
};

describe("Supervisor detail page", () => {
  it("polls the supervisor detail prop when automatic loading is enabled", () => {
    render(<SupervisorShow {...props} />);

    expect(screen.getByText("supervisor-1")).toBeVisible();
    expect(refresh).toHaveBeenCalledWith(5_000, ["supervisorDetails"], true);
  });

  it("submits the requested scale within the supervisor own configured bounds", () => {
    render(<SupervisorShow {...props} />);

    const input = screen.getByLabelText("Scale processes");
    fireEvent.change(input, { target: { value: "4" } });
    fireEvent.click(screen.getByRole("button", { name: "Scale" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale",
      { processes: 4 },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("warns that auto-balancing may override a manual scale", () => {
    render(<SupervisorShow {...props} />);

    expect(
      screen.getByText(
        "Horizon auto-balancing may override this value while balancing is enabled.",
        { exact: false },
      ),
    ).toBeVisible();
  });
});
