import { HistoryIcon } from "lucide-react";

import { Duration } from "@/components/duration";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import type { AttemptTimeline as AttemptTimelineValue, JobAttempt } from "@/types/jobs";

const outcomeBadges: Record<
  string,
  { label: string; variant: "success" | "destructive" | "retry" | "warning" }
> = {
  processed: { label: "Processed", variant: "success" },
  failed: { label: "Failed", variant: "destructive" },
  released: { label: "Released", variant: "retry" },
  timed_out: { label: "Timed out", variant: "warning" },
};

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function OutcomeBadge({ outcome }: { outcome: string }) {
  const details = outcomeBadges[outcome];

  if (!details) {
    return <Badge variant="outline">{outcome}</Badge>;
  }

  return <Badge variant={details.variant}>{details.label}</Badge>;
}

export function AttemptTimeline({ timeline }: { timeline: AttemptTimelineValue }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Attempt History</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {!timeline.available ? (
          <Empty className="min-h-44">
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <HistoryIcon aria-hidden="true" />
              </EmptyMedia>
              <EmptyTitle>Attempt history unavailable</EmptyTitle>
              <EmptyDescription>
                {timeline.message ?? "Attempt history could not be loaded."}
              </EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : timeline.attempts.length === 0 ? (
          <Empty className="min-h-44">
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <HistoryIcon aria-hidden="true" />
              </EmptyMedia>
              <EmptyTitle>No recorded attempts</EmptyTitle>
              <EmptyDescription>
                Attempts observed by the telemetry recorder will appear here.
              </EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <ol className="divide-y divide-border">
            {timeline.attempts.map((attempt) => (
              <AttemptRow key={`${attempt.attempt}-${attempt.occurredAt}`} attempt={attempt} />
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

function AttemptRow({ attempt }: { attempt: JobAttempt }) {
  return (
    <li className="flex flex-col gap-1.5 px-4 py-3 sm:px-6">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium text-foreground">Attempt {attempt.attempt}</span>
        <OutcomeBadge outcome={attempt.outcome} />
        <span className="text-xs text-muted-foreground">{attempt.node}</span>
        {attempt.runtimeMilliseconds !== null ? (
          <span className="text-xs text-muted-foreground">
            <Duration seconds={attempt.runtimeMilliseconds / 1000} format="precise" />
          </span>
        ) : null}
        <span className="ml-auto text-xs text-muted-foreground tabular-nums">
          {dateFormatter.format(attempt.occurredAt * 1000)}
        </span>
      </div>
      {attempt.exceptionClass ? (
        <div className="flex flex-col gap-0.5 rounded-md bg-muted/50 px-3 py-2">
          <div className="flex items-center gap-2">
            <span className="font-mono text-xs font-medium text-foreground">
              {attempt.exceptionClass}
            </span>
            {attempt.fingerprint ? (
              <span className="font-mono text-[11px] text-muted-foreground">
                #{attempt.fingerprint}
              </span>
            ) : null}
          </div>
          {attempt.message ? (
            <p className="text-xs break-words text-muted-foreground">{attempt.message}</p>
          ) : null}
        </div>
      ) : null}
    </li>
  );
}
