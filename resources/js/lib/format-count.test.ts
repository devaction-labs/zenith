import { describe, expect, it } from "vite-plus/test";

import { formatCount } from "@/lib/format-count";

const fullCountFormatter = new Intl.NumberFormat();
const compactCountFormatter = new Intl.NumberFormat(undefined, {
  notation: "compact",
  compactDisplay: "short",
  maximumSignificantDigits: 4,
});

describe("formatCount", () => {
  it.each([0, 9_032, 9_999])(
    "uses full runtime-locale formatting below the compact threshold for %i",
    (count) => {
      expect(formatCount(count)).toBe(fullCountFormatter.format(count));
    },
  );

  it.each([10_000, 10_320, 100_300, 1_000_000])(
    "uses compact runtime-locale formatting at and above the threshold for %i",
    (count) => {
      expect(formatCount(count)).toBe(compactCountFormatter.format(count));
    },
  );
});
