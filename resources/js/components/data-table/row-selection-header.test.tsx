import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { RowSelectionHeaderCell } from "@/components/data-table/row-selection-header";

const loaded50 = Array.from({ length: 50 }, (_, index) => `job-${index}`);
const loaded120 = Array.from({ length: 120 }, (_, index) => `job-${index}`);

describe("RowSelectionHeaderCell", () => {
  it("selects every loaded row directly from the checkbox when only one page is loaded", () => {
    const onSelectIds = vi.fn();
    const onClear = vi.fn();

    render(
      <table>
        <thead>
          <tr>
            <RowSelectionHeaderCell
              label="pending jobs"
              loadedIds={loaded50}
              selectedCount={0}
              onSelectIds={onSelectIds}
              onClear={onClear}
            />
          </tr>
        </thead>
      </table>,
    );

    expect(
      screen.queryByRole("button", { name: "pending jobs selection options" }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("checkbox", { name: "Select all loaded pending jobs" }));

    expect(onSelectIds).toHaveBeenCalledWith(loaded50);
  });

  it("clears the selection when the header checkbox is checked", () => {
    const onSelectIds = vi.fn();
    const onClear = vi.fn();

    render(
      <table>
        <thead>
          <tr>
            <RowSelectionHeaderCell
              label="pending jobs"
              loadedIds={loaded50}
              selectedCount={50}
              onSelectIds={onSelectIds}
              onClear={onClear}
            />
          </tr>
        </thead>
      </table>,
    );

    fireEvent.click(screen.getByRole("checkbox", { name: "Select all loaded pending jobs" }));

    expect(onClear).toHaveBeenCalledTimes(1);
    expect(onSelectIds).not.toHaveBeenCalled();
  });

  it("offers page and all-loaded selection modes once more than one page is loaded", async () => {
    const onSelectIds = vi.fn();
    const onClear = vi.fn();

    render(
      <table>
        <thead>
          <tr>
            <RowSelectionHeaderCell
              label="failed jobs"
              loadedIds={loaded120}
              selectedCount={0}
              onSelectIds={onSelectIds}
              onClear={onClear}
            />
          </tr>
        </thead>
      </table>,
    );

    fireEvent.pointerDown(screen.getByRole("button", { name: "failed jobs selection options" }), {
      button: 0,
      ctrlKey: false,
    });
    fireEvent.click(await screen.findByRole("menuitem", { name: "Select page (50)" }));

    expect(onSelectIds).toHaveBeenCalledWith(loaded120.slice(0, 50));

    fireEvent.pointerDown(screen.getByRole("button", { name: "failed jobs selection options" }), {
      button: 0,
      ctrlKey: false,
    });
    fireEvent.click(await screen.findByRole("menuitem", { name: "Select all loaded (120)" }));

    expect(onSelectIds).toHaveBeenCalledWith(loaded120);
  });
});
