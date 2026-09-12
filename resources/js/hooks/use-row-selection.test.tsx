import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { useRowSelection } from "@/hooks/use-row-selection";

describe("useRowSelection", () => {
  it("toggles individual rows in and out of the selection", () => {
    const { result } = renderHook(() => useRowSelection());

    expect(result.current.selectedCount).toBe(0);

    act(() => result.current.toggle("job-1"));
    expect(result.current.isSelected("job-1")).toBe(true);
    expect(result.current.selectedCount).toBe(1);

    act(() => result.current.toggle("job-1"));
    expect(result.current.isSelected("job-1")).toBe(false);
    expect(result.current.selectedCount).toBe(0);
  });

  it("replaces the whole selection when selecting an explicit id list", () => {
    const { result } = renderHook(() => useRowSelection());

    act(() => result.current.toggle("job-1"));
    act(() => result.current.selectIds(["job-2", "job-3"]));

    expect(result.current.isSelected("job-1")).toBe(false);
    expect(result.current.isSelected("job-2")).toBe(true);
    expect(result.current.isSelected("job-3")).toBe(true);
    expect(result.current.selectedCount).toBe(2);
  });

  it("survives a re-render that grows the underlying row collection", () => {
    const { result, rerender } = renderHook(
      (rowCount: number) => {
        const selection = useRowSelection();

        return { selection, rowCount };
      },
      { initialProps: 1 },
    );

    act(() => result.current.selection.toggle("job-1"));
    rerender(50);

    expect(result.current.selection.isSelected("job-1")).toBe(true);
    expect(result.current.selection.selectedCount).toBe(1);
  });

  it("clears the whole selection", () => {
    const { result } = renderHook(() => useRowSelection());

    act(() => result.current.selectIds(["job-1", "job-2"]));
    act(() => result.current.clear());

    expect(result.current.selectedCount).toBe(0);
  });
});
