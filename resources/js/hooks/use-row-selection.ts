import { useCallback, useMemo, useState } from "react";

export type RowSelection = {
  selectedIds: ReadonlySet<string>;
  selectedCount: number;
  isSelected: (id: string) => boolean;
  toggle: (id: string) => void;
  selectIds: (ids: readonly string[]) => void;
  clear: () => void;
};

/**
 * Tracks a set of selected row ids independently of the rows currently
 * rendered. Because the set lives in its own state, it is never reset when
 * an infinite-scroll page appends more rows to the underlying collection.
 */
export function useRowSelection(): RowSelection {
  const [selectedIds, setSelectedIds] = useState<ReadonlySet<string>>(() => new Set());

  const toggle = useCallback((id: string) => {
    setSelectedIds((current) => {
      const next = new Set(current);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
  }, []);

  const selectIds = useCallback((ids: readonly string[]) => {
    setSelectedIds(new Set(ids));
  }, []);

  const clear = useCallback(() => {
    setSelectedIds(new Set());
  }, []);

  const isSelected = useCallback((id: string) => selectedIds.has(id), [selectedIds]);

  return useMemo(
    () => ({
      selectedIds,
      selectedCount: selectedIds.size,
      isSelected,
      toggle,
      selectIds,
      clear,
    }),
    [selectedIds, isSelected, toggle, selectIds, clear],
  );
}
