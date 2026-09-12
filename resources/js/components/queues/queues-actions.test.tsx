import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { QueuesActions } from "@/components/queues/queues-actions";

const inertia = vi.hoisted(() => ({ delete: vi.fn(), post: vi.fn() }));

vi.mock("@inertiajs/react", () => ({
  router: inertia,
  usePage: () => ({ props: { horizon: {} } }),
}));

describe("QueuesActions", () => {
  beforeEach(() => {
    inertia.delete.mockReset();
    inertia.post.mockReset();
  });

  it("pauses every queue through the global pause endpoint", async () => {
    render(
      <QueuesActions horizonBaseUrl="/horizon" queueCount={2} pendingJobs={0} queuePausingAll />,
    );

    fireEvent.pointerDown(screen.getByRole("button", { name: "Queue list actions" }), {
      button: 0,
      ctrlKey: false,
    });
    fireEvent.click(await screen.findByRole("menuitem", { name: "Pause all queues" }));
    fireEvent.click(screen.getByRole("button", { name: "Pause all queues" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/queues/pause-all",
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("clears only the global pause when all queues are paused", async () => {
    render(
      <QueuesActions
        horizonBaseUrl="/horizon"
        queueCount={2}
        pendingJobs={4}
        allPaused
        queuePausingAll
      />,
    );

    fireEvent.pointerDown(screen.getByRole("button", { name: "Queue list actions" }), {
      button: 0,
      ctrlKey: false,
    });
    fireEvent.click(await screen.findByRole("menuitem", { name: "Resume all queues" }));
    fireEvent.click(screen.getByRole("button", { name: "Resume all queues" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/queues/pause-all",
      expect.objectContaining({ preserveScroll: true }),
    );
  });
});
