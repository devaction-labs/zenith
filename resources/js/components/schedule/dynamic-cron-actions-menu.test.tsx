import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { DynamicCronActionsMenu } from "@/components/schedule/dynamic-cron-actions-menu";

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock("@inertiajs/react", () => ({ router: inertia }));

function openMenu(name: string) {
  fireEvent.pointerDown(screen.getByRole("button", { name: `${name} cron actions` }), {
    button: 0,
    ctrlKey: false,
  });
}

describe("DynamicCronActionsMenu", () => {
  beforeEach(() => {
    inertia.post.mockReset();
    inertia.delete.mockReset();
  });

  it("calls onEdit when Edit is chosen", async () => {
    const onEdit = vi.fn();

    render(
      <DynamicCronActionsMenu
        name="nightly"
        cronId={7}
        paused={false}
        horizonBaseUrl="/horizon"
        onEdit={onEdit}
      />,
    );

    openMenu("nightly");
    fireEvent.click(await screen.findByRole("menuitem", { name: "Edit" }));

    expect(onEdit).toHaveBeenCalledOnce();
  });

  it("posts to the pause route when the cron is running", async () => {
    render(
      <DynamicCronActionsMenu
        name="nightly"
        cronId={7}
        paused={false}
        horizonBaseUrl="/horizon"
        onEdit={vi.fn()}
      />,
    );

    openMenu("nightly");
    fireEvent.click(await screen.findByRole("menuitem", { name: "Pause" }));

    expect(inertia.post).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons/7/pause",
      {},
      expect.anything(),
    );
  });

  it("deletes the pause route when the cron is paused", async () => {
    render(
      <DynamicCronActionsMenu
        name="nightly"
        cronId={7}
        paused={true}
        horizonBaseUrl="/horizon"
        onEdit={vi.fn()}
      />,
    );

    openMenu("nightly");
    fireEvent.click(await screen.findByRole("menuitem", { name: "Resume" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons/7/pause",
      expect.anything(),
    );
  });

  it("confirms before deleting the cron", async () => {
    render(
      <DynamicCronActionsMenu
        name="nightly"
        cronId={7}
        paused={false}
        horizonBaseUrl="/horizon"
        onEdit={vi.fn()}
      />,
    );

    openMenu("nightly");
    fireEvent.click(await screen.findByRole("menuitem", { name: "Delete" }));

    expect(screen.getByRole("dialog", { name: "Delete nightly?" })).toBeVisible();
    expect(inertia.delete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Delete" }));

    expect(inertia.delete).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons/7",
      expect.anything(),
    );
  });
});
