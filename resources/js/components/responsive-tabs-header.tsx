import { useLayoutEffect, useRef } from "react";

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
  const tabsListRef = useRef<HTMLDivElement>(null);
  const selectedItem = items.find((item) => item.value === value);

  useLayoutEffect(() => {
    const list = tabsListRef.current;
    const activeTab = list?.querySelector<HTMLElement>("[data-active]");

    if (!list || !activeTab) {
      return;
    }

    const listBounds = list.getBoundingClientRect();
    const tabBounds = activeTab.getBoundingClientRect();

    if (tabBounds.left < listBounds.left) {
      list.scrollLeft -= listBounds.left - tabBounds.left;
    } else if (tabBounds.right > listBounds.right) {
      list.scrollLeft += tabBounds.right - listBounds.right;
    }
  }, [value]);

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
        onValueChange={(nextValue) => onValueChange(nextValue as Value | null)}
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
        ref={tabsListRef}
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
