import type { SortDirection } from "@/hooks/use-sortable-rows";

export type ControlledTableSorting = {
  key: string | null;
  direction: SortDirection;
  columns: readonly string[];
  onSort: (key: string) => void;
};

export function controlledSortHeader(sorting: ControlledTableSorting | undefined, key: string) {
  if (!sorting?.columns.includes(key)) {
    return {};
  }

  return {
    columnKey: key,
    direction: sorting.key === key ? sorting.direction : undefined,
    onSort: sorting.onSort,
  };
}
