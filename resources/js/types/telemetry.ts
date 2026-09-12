export type TelemetryGroupBy = "state" | "queue" | "class" | "node";
export type TelemetryWindow = "15m" | "1h" | "6h" | "24h" | "7d";

export const telemetryGroupByOptions: ReadonlyArray<{ value: TelemetryGroupBy; label: string }> = [
  { value: "state", label: "State" },
  { value: "queue", label: "Queue" },
  { value: "class", label: "Job class" },
  { value: "node", label: "Node" },
];

export const telemetryWindowOptions: ReadonlyArray<{ value: TelemetryWindow; label: string }> = [
  { value: "15m", label: "Last 15 minutes" },
  { value: "1h", label: "Last hour" },
  { value: "6h", label: "Last 6 hours" },
  { value: "24h", label: "Last 24 hours" },
  { value: "7d", label: "Last 7 days" },
];

export type ThroughputSeriesPoint = {
  timestamp: number;
  count: number;
};

export type ThroughputSeries = {
  label: string;
  points: ThroughputSeriesPoint[];
};

export type ThroughputChart = {
  available: boolean;
  series: ThroughputSeries[];
  message: string | null;
};

export type PercentilePoint = {
  timestamp: number;
  p50: number | null;
  p95: number | null;
  p99: number | null;
};

export type PercentileChart = {
  available: boolean;
  points: PercentilePoint[];
  message: string | null;
};
