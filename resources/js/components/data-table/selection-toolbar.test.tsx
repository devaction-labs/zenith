import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { SelectionToolbar } from "@/components/data-table/selection-toolbar";

describe("SelectionToolbar", () => {
  it("renders nothing when nothing is selected", () => {
    const { container } = render(<SelectionToolbar count={0} noun="job" onClear={vi.fn()} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("pluralizes the selection count and clears the selection", () => {
    const onClear = vi.fn();

    render(<SelectionToolbar count={2} noun="job" onClear={onClear} />);

    expect(screen.getByText("2 jobs selected")).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "Clear" }));
    expect(onClear).toHaveBeenCalledOnce();
  });

  it("uses the singular noun for a single selected row", () => {
    render(<SelectionToolbar count={1} noun="job" onClear={vi.fn()} />);

    expect(screen.getByText("1 job selected")).toBeVisible();
  });
});
