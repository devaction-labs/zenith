const numberFormatter = new Intl.NumberFormat("en-US");
const preciseNumberFormatter = new Intl.NumberFormat("en-US", {
  maximumFractionDigits: 2,
});

export type DurationFormat = "approximate" | "precise";

const preciseUnits = [
  { suffix: "y", seconds: 31_536_000 },
  { suffix: "mo", seconds: 2_592_000 },
  { suffix: "d", seconds: 86_400 },
  { suffix: "h", seconds: 3_600 },
  { suffix: "m", seconds: 60 },
  { suffix: "s", seconds: 1 },
] as const;
const maximumPreciseParts = 3;
const minimumSecondsDisplay = 1;

export function formatDuration(seconds: number, format: DurationFormat = "approximate"): string {
  const normalizedDuration = normalizeDuration(seconds);

  if (normalizedDuration > 0 && normalizedDuration < minimumSecondsDisplay) {
    return formatMilliseconds(normalizedDuration);
  }

  const duration = roundToHundredth(normalizedDuration);

  if (format === "precise") {
    return formatPreciseDuration(duration);
  }

  if (duration <= 5) {
    return formatUnit(duration, "s", preciseNumberFormatter);
  }

  if (duration < 60) {
    return formatUnit(Math.round(duration), "s");
  }

  if (duration < 90) {
    return "1m";
  }

  if (duration < 2_700) {
    return formatUnit(Math.round(duration / 60), "m");
  }

  if (duration < 5_400) {
    return "1h";
  }

  if (duration < 79_200) {
    return formatUnit(Math.round(duration / 3_600), "h");
  }

  if (duration < 129_600) {
    return "1d";
  }

  if (duration < 2_592_000) {
    return formatUnit(Math.round(duration / 86_400), "d");
  }

  if (duration < 5_184_000) {
    return "1mo";
  }

  if (duration < 31_536_000) {
    return formatUnit(Math.round(duration / 2_592_000), "mo");
  }

  if (duration < 63_072_000) {
    return "1y";
  }

  return formatUnit(Math.round(duration / 31_536_000), "y");
}

function formatPreciseDuration(duration: number): string {
  let remainingSeconds = duration;
  const parts: string[] = [];

  for (const unit of preciseUnits) {
    if (parts.length === maximumPreciseParts) {
      break;
    }

    const value =
      unit.seconds === 1 ? remainingSeconds : Math.floor(remainingSeconds / unit.seconds);

    if (value <= 0) {
      continue;
    }

    parts.push(formatUnit(value, unit.suffix, preciseNumberFormatter));
    remainingSeconds -= value * unit.seconds;
  }

  return parts.length > 0 ? parts.join(" ") : "0s";
}

function normalizeDuration(seconds: number): number {
  return Number.isFinite(seconds) ? Math.max(0, seconds) : 0;
}

function roundToHundredth(value: number): number {
  return Math.round(value * 100) / 100;
}

function formatMilliseconds(seconds: number): string {
  return formatUnit(seconds * 1_000, "ms", preciseNumberFormatter);
}

function formatUnit(
  value: number,
  suffix: string,
  formatter: Intl.NumberFormat = numberFormatter,
): string {
  return `${formatter.format(value)}${suffix}`;
}

export function formatRawDuration(seconds: number): string {
  const duration = normalizeDuration(seconds);

  if (duration > 0 && duration < minimumSecondsDisplay) {
    return formatMilliseconds(duration);
  }

  return formatUnit(roundToHundredth(duration), "s", preciseNumberFormatter);
}
