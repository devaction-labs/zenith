import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { formatDuration } from "@/lib/format-duration";
import { cn } from "@/lib/utils";
import type { ScheduleRun, ScheduleRunStatus } from "@/types/schedule";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

const barClassName: Record<ScheduleRunStatus, string> = {
  success: "bg-status-running",
  failed: "bg-status-unavailable",
  skipped: "bg-status-paused",
};

function runDescription(run: ScheduleRun): string {
  const parts: string[] = [run.status];

  if (run.durationMs !== null) {
    parts.push(formatDuration(run.durationMs / 1000));
  }

  parts.push(dateFormatter.format(run.startedAt * 1000));

  return parts.join(" · ");
}

export function RunHistorySparkline({ history }: { history: ScheduleRun[] }) {
  if (history.length === 0) {
    return <span className="text-xs text-muted-foreground">No runs recorded</span>;
  }

  const chronological = [...history].reverse();

  return (
    <div
      className="flex items-center gap-0.5"
      role="img"
      aria-label={`Last ${chronological.length} runs, most recent last`}
    >
      {chronological.map((run, index) => (
        <Tooltip key={index}>
          <TooltipTrigger
            render={
              <span
                tabIndex={0}
                className={cn(
                  "inline-block h-3.5 w-1.5 rounded-xs outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  barClassName[run.status],
                )}
              />
            }
          />
          <TooltipContent>{runDescription(run)}</TooltipContent>
        </Tooltip>
      ))}
    </div>
  );
}
