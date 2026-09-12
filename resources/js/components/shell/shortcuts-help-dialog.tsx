import { Fragment } from "react";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

const shortcuts: ReadonlyArray<{ keys: string; description: string }> = [
  { keys: "g d", description: "Go to the dashboard" },
  { keys: "g j", description: "Go to pending jobs" },
  { keys: "g f", description: "Go to failed jobs" },
  { keys: "/", description: "Focus this page's search box" },
  { keys: "?", description: "Show this help dialog" },
];

export function ShortcutsHelpDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Keyboard shortcuts</DialogTitle>
          <DialogDescription>
            Shortcuts never fire while typing in a field or menu.
          </DialogDescription>
        </DialogHeader>
        <dl className="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2.5 text-sm">
          {shortcuts.map((shortcut) => (
            <Fragment key={shortcut.keys}>
              <dt>
                <kbd className="rounded-sm border border-border bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                  {shortcut.keys}
                </kbd>
              </dt>
              <dd className="text-foreground">{shortcut.description}</dd>
            </Fragment>
          ))}
        </dl>
      </DialogContent>
    </Dialog>
  );
}
