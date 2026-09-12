import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { RetryRetainedJobButton } from "@/components/jobs/retained-job-actions";

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
const abilities = vi.hoisted(() => ({ retryJobs: true }));

vi.mock("@inertiajs/react", () => ({
  router: inertia,
  usePage: () => ({ props: { horizon: {} } }),
}));

vi.mock("@/hooks/use-horizon-abilities", () => ({
  useHorizonAbilities: () => abilities,
}));

describe("retained job actions", () => {
  beforeEach(() => {
    inertia.post.mockReset();
    abilities.retryJobs = true;
  });

  it("posts a retry for a completed job through the type-scoped route", () => {
    render(
      <RetryRetainedJobButton type="completed" jobId="completed-1" horizonBaseUrl="/horizon" />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Retry job" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/jobs/completed/completed-1/retry",
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("posts a retry for a silenced job through the type-scoped route", () => {
    render(<RetryRetainedJobButton type="silenced" jobId="silenced-1" horizonBaseUrl="/horizon" />);

    fireEvent.click(screen.getByRole("button", { name: "Retry job" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/jobs/silenced/silenced-1/retry",
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it("exposes its working state while the retry request is in flight", () => {
    render(
      <RetryRetainedJobButton type="completed" jobId="completed-1" horizonBaseUrl="/horizon" />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Retry job" }));
    const options = inertia.post.mock.calls[0]?.[2];

    act(() => {
      options.onStart();
    });

    expect(screen.getByRole("button", { name: "Retrying job" })).toBeDisabled();
  });

  it("disables the button when the retryJobs ability is denied", () => {
    abilities.retryJobs = false;

    render(
      <RetryRetainedJobButton type="completed" jobId="completed-1" horizonBaseUrl="/horizon" />,
    );

    expect(screen.getByRole("button", { name: "Retry job" })).toBeDisabled();
  });
});
