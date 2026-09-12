import { Button } from "@/components/ui/button";

export function SelectionToolbar({
  count,
  noun,
  onClear,
  children,
}: {
  count: number;
  noun: string;
  onClear: () => void;
  children?: React.ReactNode;
}) {
  if (count === 0) {
    return null;
  }

  return (
    <div className="flex min-h-10 items-center justify-between gap-2 border-b border-separator bg-muted/40 px-4 py-1.5 sm:px-6">
      <span className="text-sm text-muted-foreground">
        {count} {count === 1 ? noun : `${noun}s`} selected
      </span>
      <div className="flex items-center gap-2">
        {children}
        <Button type="button" variant="ghost" size="sm" onClick={onClear}>
          Clear
        </Button>
      </div>
    </div>
  );
}
