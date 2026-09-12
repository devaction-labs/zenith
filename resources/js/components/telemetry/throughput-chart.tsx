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
import type { ThroughputSeries } from "@/types/telemetry";

const seriesColors = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
] as const;

const timeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: "2-digit",
  minute: "2-digit",
});

function formatTime(timestamp: number) {
  return timeFormatter.format(new Date(timestamp * 1000));
}

export function seriesDataKey(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

type ThroughputTooltipPayload = readonly {
  payload?: {
    timestamp?: unknown;
  };
}[];

export function formatThroughputTooltipLabel(value: unknown, payload?: ThroughputTooltipPayload) {
  const timestamp = Number(payload?.[0]?.payload?.timestamp);

  if (Number.isFinite(timestamp)) {
    return formatTime(timestamp);
  }

  return typeof value === "string" ? value : "";
}

export function buildThroughputChartData(series: readonly ThroughputSeries[]): {
  data: Array<Record<string, number>>;
  config: ChartConfig;
} {
  const config: ChartConfig = {};
  const timestamps = new Set<number>();

  series.forEach((item, index) => {
    item.points.forEach((point) => timestamps.add(point.timestamp));
    config[seriesDataKey(item.label)] = {
      label: item.label,
      color: seriesColors[index % seriesColors.length],
    };
  });

  const sortedTimestamps = [...timestamps].sort((left, right) => left - right);

  const data = sortedTimestamps.map((timestamp) => {
    const row: Record<string, number> = { timestamp };

    series.forEach((item) => {
      const point = item.points.find((candidate) => candidate.timestamp === timestamp);
      row[seriesDataKey(item.label)] = point?.count ?? 0;
    });

    return row;
  });

  return { data, config };
}

export function ThroughputChart({ series }: { series: readonly ThroughputSeries[] }) {
  const { data, config } = useMemo(() => buildThroughputChartData(series), [series]);

  if (series.length === 0 || data.length === 0) {
    return (
      <Empty className="min-h-64">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <MetricsNavigationIcon aria-hidden="true" />
          </EmptyMedia>
          <EmptyTitle>Not Enough Data</EmptyTitle>
          <EmptyDescription>No jobs have been recorded for this window yet.</EmptyDescription>
        </EmptyHeader>
      </Empty>
    );
  }

  return (
    <ChartContainer
      config={config}
      className="h-64 w-full aspect-auto"
      role="img"
      aria-label="Live throughput chart"
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
          width={48}
          tick={{ textAnchor: "end" }}
          allowDecimals={false}
          tickFormatter={(value: number) => Math.round(value).toLocaleString()}
        />
        <ChartTooltip
          cursor={false}
          content={
            <ChartTooltipContent indicator="line" labelFormatter={formatThroughputTooltipLabel} />
          }
        />
        <ChartLegend content={<ChartLegendContent />} />
        {series.map((item, index) => {
          const key = seriesDataKey(item.label);

          return (
            <Line
              key={key}
              type="monotone"
              dataKey={key}
              stroke={seriesColors[index % seriesColors.length]}
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
          );
        })}
      </LineChart>
    </ChartContainer>
  );
}
