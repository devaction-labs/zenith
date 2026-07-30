import { Duration } from "@/components/duration";
import { Badge } from "@/components/ui/badge";
import {
  Statistic,
  StatisticDetail,
  StatisticDetails,
  StatisticLabel,
} from "@/components/ui/statistic";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { QueueWaitThreshold, QueueWaitThresholdStatus } from "@/types/queues";

const statusDetails = {
  exceeded: {
    label: "Exceeded",
    variant: "destructive" as const,
  },
  within_bounds: {
    label: "Within bounds",
    variant: "success" as const,
  },
} satisfies Record<Exclude<QueueWaitThresholdStatus, "calculating" | "disabled">, object>;

export const queueWaitThresholdSortRank = {
  exceeded: 0,
  calculating: 1,
  disabled: 2,
  within_bounds: 3,
} satisfies Record<QueueWaitThresholdStatus, number>;

export function QueueWaitThresholdBadge({
  status,
  label,
}: {
  status: QueueWaitThresholdStatus;
  /** Optional display override (e.g. shared-pool parent “2 exceeded”). */
  label?: string;
}) {
  if (status === "disabled" || status === "calculating") {
    const description =
      status === "disabled" ? "Wait monitoring disabled" : "Waiting for runtime data";

    return (
      <Tooltip>
        <TooltipTrigger
          render={
            <span
              className="inline-flex h-5 min-w-5 items-center text-sm text-muted-foreground"
              aria-label={description}
              data-status={status}
            >
              —
            </span>
          }
        />
        <TooltipContent>{description}</TooltipContent>
      </Tooltip>
    );
  }

  const details = statusDetails[status];

  return (
    <Badge variant={details.variant} data-status={status}>
      {label ?? details.label}
    </Badge>
  );
}

export function QueueWaitThresholdCell({
  waitThreshold,
  label,
}: {
  waitThreshold?: QueueWaitThreshold;
  label?: string;
}) {
  return <QueueWaitThresholdBadge status={waitThreshold?.status ?? "disabled"} label={label} />;
}

export function QueueWaitThresholdMetric({ waitThreshold }: { waitThreshold: QueueWaitThreshold }) {
  const multipleConnections = waitThreshold.targets.length > 1;
  const waitLabel = multipleConnections
    ? `Estimated wait (${waitThreshold.decisiveConnection})`
    : "Estimated wait";
  const oldestLabel =
    multipleConnections && waitThreshold.oldestReadyConnection
      ? `Oldest ready (${waitThreshold.oldestReadyConnection})`
      : "Oldest ready";

  return (
    <Statistic>
      <div className="flex items-center justify-between gap-3">
        <StatisticLabel>Wait Threshold</StatisticLabel>
        <QueueWaitThresholdBadge status={waitThreshold.status} />
      </div>
      <StatisticDetails>
        <WaitThresholdDetail
          label={waitLabel}
          value={
            waitThreshold.waitSeconds === null ? (
              "—"
            ) : (
              <Duration seconds={waitThreshold.waitSeconds} />
            )
          }
        />
        <WaitThresholdDetail
          label="Threshold"
          value={
            waitThreshold.status === "disabled" ? (
              "—"
            ) : (
              <Duration seconds={waitThreshold.thresholdSeconds} />
            )
          }
        />
        <WaitThresholdDetail
          label={oldestLabel}
          value={
            waitThreshold.oldestReadyAgeSeconds === null ? (
              "—"
            ) : (
              <Duration seconds={waitThreshold.oldestReadyAgeSeconds} />
            )
          }
        />
      </StatisticDetails>
    </Statistic>
  );
}

function WaitThresholdDetail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <StatisticDetail>
      <span className="text-muted-foreground">{label}</span>
      <span className="font-normal text-muted-foreground">{value}</span>
    </StatisticDetail>
  );
}
