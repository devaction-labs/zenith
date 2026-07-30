import { describe, expect, it } from "vite-plus/test";

import { formatDuration, formatRawDuration } from "@/lib/format-duration";

describe("formatDuration", () => {
  it.each([
    [0.001, "1ms"],
    [0.01, "10ms"],
    [0.1, "100ms"],
    [0.999, "999ms"],
    [1, "1s"],
    [3, "3s"],
    [75, "1m"],
    [3_600, "1h"],
    [90_000, "1d"],
    [2_592_000, "1mo"],
    [31_536_000, "1y"],
  ])("formats an approximate duration of %s seconds as %s", (seconds, expected) => {
    expect(formatDuration(seconds)).toBe(expected);
  });

  it.each([
    [0, "0s"],
    [-1, "0s"],
    [0.001, "1ms"],
    [0.01, "10ms"],
    [0.1, "100ms"],
    [0.999, "999ms"],
    [1, "1s"],
    [59.999, "1m"],
    [5_552, "1h 32m 32s"],
    [36_979_200, "1y 2mo 3d"],
  ])("formats a precise duration of %s seconds as %s", (seconds, expected) => {
    expect(formatDuration(seconds, "precise")).toBe(expected);
  });
});

describe("formatRawDuration", () => {
  it("uses the abbreviated seconds suffix", () => {
    expect(formatRawDuration(0.01)).toBe("10ms");
    expect(formatRawDuration(0.1)).toBe("100ms");
    expect(formatRawDuration(0.999)).toBe("999ms");
    expect(formatRawDuration(1)).toBe("1s");
    expect(formatRawDuration(5_552)).toBe("5,552s");
  });
});
