import { ChevronDownIcon } from "lucide-react";

import { ActionMenuTrigger } from "@/components/ui/action-menu-trigger";
import { Checkbox } from "@/components/ui/checkbox";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem } from "@/components/ui/dropdown-menu";

const PAGE_SIZE = 50;

/**
 * Header checkbox for a job table's selection column.
 *
 * Infinite scroll appends rows in fixed-size pages rather than exposing
 * discrete pages the way classic pagination would, so "select this page"
 * and "select everything loaded so far" are exposed as explicit menu
 * choices only once more than one page of eligible rows is loaded. Before
 * that, both modes select the same rows and the plain checkbox covers it.
 */
export function RowSelectionHeaderCell({
  label,
  loadedIds,
  selectedCount,
  onSelectIds,
  onClear,
}: {
  label: string;
  loadedIds: readonly string[];
  selectedCount: number;
  onSelectIds: (ids: readonly string[]) => void;
  onClear: () => void;
}) {
  const total = loadedIds.length;
  const checked = total > 0 && selectedCount >= total;
  const indeterminate = selectedCount > 0 && selectedCount < total;
  const page = loadedIds.slice(0, PAGE_SIZE);
  const hasMultiplePages = total > page.length;

  return (
    <div className="flex items-center gap-1">
      <Checkbox
        aria-label={`Select all loaded ${label}`}
        disabled={total === 0}
        checked={checked}
        indeterminate={indeterminate}
        onCheckedChange={() => {
          if (selectedCount > 0) {
            onClear();
          } else {
            onSelectIds(loadedIds);
          }
        }}
      />
      {hasMultiplePages ? (
        <DropdownMenu>
          <ActionMenuTrigger available label={`${label} selection options`}>
            <ChevronDownIcon className="size-3.5" />
          </ActionMenuTrigger>
          <DropdownMenuContent align="start" className="w-56">
            <DropdownMenuItem onSelect={() => onSelectIds(page)}>
              Select page ({page.length})
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => onSelectIds(loadedIds)}>
              Select all loaded ({total})
            </DropdownMenuItem>
            <DropdownMenuItem disabled={selectedCount === 0} onSelect={onClear}>
              Clear selection
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}
    </div>
  );
}
