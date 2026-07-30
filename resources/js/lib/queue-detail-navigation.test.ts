import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { prefetchQueueDetail, visitQueueDetail } from "@/lib/queue-detail-navigation";

const inertia = vi.hoisted(() => ({
  getCached: vi.fn(),
  prefetch: vi.fn(),
  visit: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: inertia,
}));

vi.mock("@inertiajs/core", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/core")>();

  return {
    ...actual,
    shouldIntercept: vi.fn(() => true),
  };
});

describe("queue detail navigation", () => {
  beforeEach(() => {
    inertia.getCached.mockReset();
    inertia.prefetch.mockReset();
    inertia.visit.mockReset();
  });

  it("prefetches an uncached queue detail from the entire workload row", () => {
    inertia.getCached.mockReturnValue(null);

    prefetchQueueDetail("/horizon/queues/reports");

    expect(inertia.prefetch).toHaveBeenCalledWith("/horizon/queues/reports");
  });

  it("does not duplicate a cached or in-flight queue prefetch", () => {
    inertia.getCached.mockReturnValue({ inFlight: true });

    prefetchQueueDetail("/horizon/queues/reports");

    expect(inertia.prefetch).not.toHaveBeenCalled();
  });

  it("navigates without synthesizing an intermediate queue skeleton page", () => {
    visitQueueDetail("/horizon/queues/reports");

    expect(inertia.visit).toHaveBeenCalledWith("/horizon/queues/reports");
  });
});
