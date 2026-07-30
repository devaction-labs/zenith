import { useEffect, useRef } from "react";

import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";

export type ResponsiveTabItem<Value extends string> = {
  value: Value;
  label: string;
  desktopLabel?: React.ReactNode;
  count?: React.ReactNode;
  render?: React.ComponentProps<typeof TabsTrigger>["render"];
  ariaLabel?: string;
  disabled?: boolean;
};

/**
 * Compact (select) + desktop (line tabs) header for every panel-level tab set.
 *
 * Tab selection may activate the tab and sync URL-backed state, but must never
 * move the document viewport. Mobile Select restores pre-selection scroll after
 * popup close. Desktop tabs intentionally do not auto-scroll the strip.
 */
export function ResponsiveTabsHeader<Value extends string>({
  value,
  items,
  ariaLabel,
  optionsLabel = `${ariaLabel} options`,
  onValueChange,
  actions,
  separatedFromHeader,
  className,
  tabsListClassName,
  triggerClassName,
}: {
  value: Value;
  items: readonly ResponsiveTabItem<Value>[];
  ariaLabel: string;
  optionsLabel?: string;
  onValueChange: (value: Value | null) => void;
  actions?: React.ReactNode;
  separatedFromHeader?: boolean;
  className?: string;
  tabsListClassName?: string;
  triggerClassName?: string;
}) {
  const pendingSelectScroll = useRef<{ scrollX: number; scrollY: number } | null>(null);
  const selectScrollRestoreFrame = useRef<number | null>(null);
  const selectedItem = items.find((item) => item.value === value);

  useEffect(() => {
    return () => {
      if (selectScrollRestoreFrame.current !== null) {
        window.cancelAnimationFrame(selectScrollRestoreFrame.current);
      }
    };
  }, []);

  const selectTab = (nextValue: Value | null) => {
    // Capture before the tab switch; Base UI may still scroll to top while the
    // popup closes after the value commit.
    pendingSelectScroll.current = {
      scrollX: window.scrollX,
      scrollY: window.scrollY,
    };
    onValueChange(nextValue);
  };

  const restoreSelectScrollAfterClose = (open: boolean) => {
    if (open) {
      return;
    }

    const pending = pendingSelectScroll.current;

    if (pending === null) {
      return;
    }

    if (selectScrollRestoreFrame.current !== null) {
      window.cancelAnimationFrame(selectScrollRestoreFrame.current);
    }

    selectScrollRestoreFrame.current = window.requestAnimationFrame(() => {
      selectScrollRestoreFrame.current = null;
      pendingSelectScroll.current = null;
      window.scrollTo(pending.scrollX, pending.scrollY);
    });
  };

  return (
    <div
      className={cn(
        "flex min-h-[50px] items-stretch sm:min-h-0 sm:items-center",
        separatedFromHeader && "border-t border-separator sm:border-t-0",
        className,
      )}
    >
      <Select
        items={items.map((item) => ({
          label: item.label,
          value: item.value,
        }))}
        value={value}
        onValueChange={(nextValue) => selectTab(nextValue as Value | null)}
        onOpenChangeComplete={restoreSelectScrollAfterClose}
      >
        <SelectTrigger
          aria-label={ariaLabel}
          className="min-w-0 flex-1 rounded-none border-0 bg-transparent px-4 py-0 shadow-none hover:bg-transparent data-[size=default]:h-auto dark:bg-transparent dark:hover:bg-transparent sm:hidden"
        >
          <SelectValue>
            {selectedItem ? (
              <>
                <span className="truncate">{selectedItem.label}</span>
                <TabCount count={selectedItem.count} />
              </>
            ) : null}
          </SelectValue>
        </SelectTrigger>
        <SelectContent align="start" alignItemWithTrigger={false} listLabel={optionsLabel}>
          <SelectGroup>
            {items.map((item) => (
              <SelectItem
                key={item.value}
                value={item.value}
                label={item.label}
                disabled={item.disabled}
              >
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                <TabCount count={item.count} />
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>

      <TabsList
        variant="line"
        aria-label={ariaLabel}
        className={cn(
          "hidden min-w-0 flex-1 justify-start gap-2 overflow-x-auto rounded-none px-3 py-0 sm:inline-flex",
          tabsListClassName,
        )}
      >
        {items.map((item) => (
          <TabsTrigger
            key={item.value}
            value={item.value}
            nativeButton={item.render ? false : undefined}
            render={item.render}
            disabled={item.disabled}
            aria-label={item.ariaLabel}
            className={cn(
              "h-auto flex-none rounded-none px-3 py-4 text-[13.5px]",
              triggerClassName,
            )}
          >
            {item.desktopLabel ?? item.label}
            <TabCount count={item.count} />
          </TabsTrigger>
        ))}
      </TabsList>

      {actions ? (
        <div className="flex shrink-0 items-center border-l border-separator px-2.5 sm:border-l-0 sm:px-0">
          {actions}
        </div>
      ) : null}
    </div>
  );
}

function TabCount({ count }: { count?: React.ReactNode }) {
  if (count === null || count === undefined) {
    return null;
  }

  return (
    <Badge className="h-4 min-w-4 shrink-0 px-1.5 text-[10.5px]" variant="secondary">
      {count}
    </Badge>
  );
}
