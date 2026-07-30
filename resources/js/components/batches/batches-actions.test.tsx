import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { BatchesActions } from "@/components/batches/batches-actions";

vi.mock("@inertiajs/react", () => ({
  router: { delete: vi.fn() },
}));

const completeCounts = {
  incomplete: 1,
  complete: 2,
  finished: 3,
  cancelled: 1,
  available: true,
  completeScan: true,
  message: null,
};

describe("BatchesActions", () => {
  it("offers clear actions only after a complete retained-history preflight", async () => {
    const { rerender } = render(
      <BatchesActions horizonBaseUrl="/horizon" counts={completeCounts} />,
    );

    fireEvent.pointerDown(screen.getByRole("button", { name: "Batch actions" }), {
      button: 0,
      ctrlKey: false,
    });

    expect(await screen.findByRole("menuitem", { name: "Clear finished batches" })).toBeEnabled();

    rerender(
      <BatchesActions
        horizonBaseUrl="/horizon"
        counts={{
          ...completeCounts,
          completeScan: false,
          message: "Retained batch history is incomplete.",
        }}
      />,
    );

    expect(screen.queryByRole("button", { name: "Batch actions" })).not.toBeInTheDocument();
  });
});
