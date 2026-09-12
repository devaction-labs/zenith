import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import {
  CancelSelectedPendingJobsButton,
  RemoveSelectedFailedJobsButton,
  RetrySelectedFailedJobsButton,
} from "@/components/jobs/selected-jobs-actions";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
const abilities = vi.hoisted(() => ({
  cancelJobs: true,
  retryJobs: true,
  clearQueues: true,
}));

vi.mock("@inertiajs/react", () => ({
  router: inertia,
  usePage: () => ({ props: { horizon: { abilities } } }),
}));

describe("CancelSelectedPendingJobsButton", () => {
  beforeEach(() => {
    inertia.delete.mockReset();
    abilities.cancelJobs = true;
  });

  it("cancels the selected pending job ids", () => {
    const onDone = vi.fn();

    render(
      <CancelSelectedPendingJobsButton
        horizonBaseUrl="/horizon"
        ids={["pending-1", "pending-2"]}
        onDone={onDone}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Cancel selected" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/jobs/pending/cancel-selected",
      expect.objectContaining({
        data: { ids: ["pending-1", "pending-2"] },
        preserveScroll: true,
      }),
    );
  });

  it("disables the action without the cancelJobs ability", () => {
    abilities.cancelJobs = false;

    render(
      <CancelSelectedPendingJobsButton
        horizonBaseUrl="/horizon"
        ids={["pending-1"]}
        onDone={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: "Cancel selected" })).toBeDisabled();
  });
});

describe("RetrySelectedFailedJobsButton", () => {
  beforeEach(() => {
    inertia.post.mockReset();
    abilities.retryJobs = true;
  });

  it("retries the selected failed job ids", () => {
    render(
      <RetrySelectedFailedJobsButton
        horizonBaseUrl="/horizon"
        ids={["failed-1", "failed-2"]}
        onDone={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Retry selected" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/failed/retry-selected",
      { ids: ["failed-1", "failed-2"] },
      expect.objectContaining({ preserveScroll: true }),
    );
  });
});

describe("RemoveSelectedFailedJobsButton", () => {
  beforeEach(() => {
    inertia.delete.mockReset();
    abilities.clearQueues = true;
  });

  it("confirms before removing the selected failed job ids", () => {
    render(
      <RemoveSelectedFailedJobsButton
        horizonBaseUrl="/horizon"
        ids={["failed-1"]}
        onDone={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Remove selected" }));
    const dialog = screen.getByRole("dialog", { name: "Remove 1 selected failed jobs?" });
    expect(dialog).toBeVisible();

    fireEvent.click(within(dialog).getByRole("button", { name: "Remove selected" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/failed/selected",
      expect.objectContaining({ data: { ids: ["failed-1"] } }),
    );
  });
});
