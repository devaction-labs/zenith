import { useMemo } from "react";
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";

import { MetricsNavigationIcon } from "@/components/navigation-icons";
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import { formatDuration } from "@/lib/format-duration";
import type { PercentilePoint } from "@/types/telemetry";

const percentileChartConfig = {
  p50: {
    label: "p50",
    color: "var(--chart-1)",
  },
  p95: {
    label: "p95",
    color: "var(--chart-2)",
  },
  p99: {
    label: "p99",
    color: "var(--chart-3)",
  },
} satisfies ChartConfig;

const timeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: "2-digit",
  minute: "2-digit",
});

function formatTime(timestamp: number) {
  return timeFormatter.format(new Date(timestamp * 1000));
}

export function formatPercentileMilliseconds(milliseconds: number) {
  return formatDuration(milliseconds / 1000, "precise");
}

type PercentileTooltipPayload = readonly {
  payload?: {
    timestamp?: unknown;
  };
}[];

export function formatPercentileTooltipLabel(value: unknown, payload?: PercentileTooltipPayload) {
  const timestamp = Number(payload?.[0]?.payload?.timestamp);

  if (Number.isFinite(timestamp)) {
    return formatTime(timestamp);
  }

  return typeof value === "string" ? value : "";
}

export function PercentileChart({ points }: { points: readonly PercentilePoint[] }) {
  const data = useMemo(
    () =>
      points.map((point) => ({
        timestamp: point.timestamp,
        p50: point.p50,
        p95: point.p95,
        p99: point.p99,
      })),
    [points],
  );
  const hasData = data.some(
    (point) => point.p50 !== null || point.p95 !== null || point.p99 !== null,
  );

  if (!hasData) {
    return (
      <Empty className="min-h-64">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <MetricsNavigationIcon aria-hidden="true" />
          </EmptyMedia>
          <EmptyTitle>Not Enough Data</EmptyTitle>
          <EmptyDescription>
            No execution time has been recorded for this window yet.
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    );
  }

  return (
    <ChartContainer
      config={percentileChartConfig}
      className="h-64 w-full aspect-auto"
      role="img"
      aria-label="Execution time percentiles chart"
      data-animation="disabled"
      initialDimension={{ width: 960, height: 256 }}
    >
      <LineChart data={data} margin={{ top: 12, right: 16, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} strokeDasharray="3 3" />
        <XAxis
          dataKey="timestamp"
          axisLine={false}
          tickLine={false}
          tickMargin={10}
          minTickGap={32}
          tickFormatter={(value: number) => formatTime(value)}
        />
        <YAxis
          axisLine={false}
          tickLine={false}
          tickMargin={10}
          width={64}
          tick={{ textAnchor: "end" }}
          tickFormatter={(value: number) => formatPercentileMilliseconds(value)}
        />
        <ChartTooltip
          cursor={false}
          content={
            <ChartTooltipContent
              indicator="line"
              labelFormatter={formatPercentileTooltipLabel}
              formatter={(value, name) => (
                <div className="flex flex-1 items-center justify-between gap-4 leading-none">
                  <span className="text-muted-foreground">{name}</span>
                  <span className="font-mono font-medium text-foreground tabular-nums">
                    {formatPercentileMilliseconds(Number(value))}
                  </span>
                </div>
              )}
            />
          }
        />
        <ChartLegend content={<ChartLegendContent />} />
        <Line
          type="monotone"
          dataKey="p50"
          stroke="var(--color-p50)"
          strokeWidth={2}
          dot={false}
          isAnimationActive={false}
          connectNulls
        />
        <Line
          type="monotone"
          dataKey="p95"
          stroke="var(--color-p95)"
          strokeWidth={2}
          dot={false}
          isAnimationActive={false}
          connectNulls
        />
        <Line
          type="monotone"
          dataKey="p99"
          stroke="var(--color-p99)"
          strokeWidth={2}
          dot={false}
          isAnimationActive={false}
          connectNulls
        />
      </LineChart>
    </ChartContainer>
  );
}
