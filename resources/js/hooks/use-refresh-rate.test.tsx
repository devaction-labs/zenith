import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vite-plus/test";

import {
  nearestRefreshRate,
  RefreshRateProvider,
  useEffectiveRefreshRate,
  useRefreshRatePreference,
} from "@/hooks/use-refresh-rate";

const STORAGE_KEY = "horizonRefreshRateMs";

function Harness({ defaultIntervalMs }: { defaultIntervalMs: number }) {
  const preference = useRefreshRatePreference();
  const effective = useEffectiveRefreshRate(defaultIntervalMs);

  return (
    <div>
      <span data-testid="effective">{effective}</span>
      <span data-testid="context">{preference?.refreshRateMs ?? "none"}</span>
      <button type="button" onClick={() => preference?.setRefreshRateMs(1000)}>
        Use 1s
      </button>
      <button type="button" onClick={() => preference?.setRefreshRateMs(0)}>
        Turn off
      </button>
    </div>
  );
}

describe("nearestRefreshRate", () => {
  it("returns off for a non-positive default interval", () => {
    expect(nearestRefreshRate(0)).toBe(0);
    expect(nearestRefreshRate(-1)).toBe(0);
  });

  it("returns the closest available option to an unmatched default interval", () => {
    expect(nearestRefreshRate(5000)).toBe(5000);
    expect(nearestRefreshRate(3000)).toBe(2000);
    expect(nearestRefreshRate(20000)).toBe(15000);
  });
});

describe("RefreshRateProvider", () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it("defaults to the nearest option for the server poll interval when nothing is stored", () => {
    render(
      <RefreshRateProvider defaultIntervalMs={5000}>
        <Harness defaultIntervalMs={5000} />
      </RefreshRateProvider>,
    );

    expect(screen.getByTestId("effective").textContent).toBe("5000");
    expect(screen.getByTestId("context").textContent).toBe("5000");
  });

  it("persists a selected refresh rate to localStorage and shares it through context", () => {
    render(
      <RefreshRateProvider defaultIntervalMs={5000}>
        <Harness defaultIntervalMs={5000} />
      </RefreshRateProvider>,
    );

    act(() => {
      screen.getByRole("button", { name: "Use 1s" }).click();
    });

    expect(screen.getByTestId("effective").textContent).toBe("1000");
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe("1000");
  });

  it("restores a previously stored preference instead of the server default", () => {
    window.localStorage.setItem(STORAGE_KEY, "15000");

    render(
      <RefreshRateProvider defaultIntervalMs={5000}>
        <Harness defaultIntervalMs={5000} />
      </RefreshRateProvider>,
    );

    expect(screen.getByTestId("effective").textContent).toBe("15000");
  });

  it("ignores a corrupt stored value and falls back to the server default", () => {
    window.localStorage.setItem(STORAGE_KEY, "not-a-number");

    render(
      <RefreshRateProvider defaultIntervalMs={2000}>
        <Harness defaultIntervalMs={2000} />
      </RefreshRateProvider>,
    );

    expect(screen.getByTestId("effective").textContent).toBe("2000");
  });

  it("persists off and disables polling", () => {
    render(
      <RefreshRateProvider defaultIntervalMs={5000}>
        <Harness defaultIntervalMs={5000} />
      </RefreshRateProvider>,
    );

    act(() => {
      screen.getByRole("button", { name: "Turn off" }).click();
    });

    expect(screen.getByTestId("effective").textContent).toBe("0");
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe("0");
  });
});

describe("useEffectiveRefreshRate without a provider", () => {
  it("falls back to the nearest option for the given interval", () => {
    render(<Harness defaultIntervalMs={15000} />);

    expect(screen.getByTestId("effective").textContent).toBe("15000");
    expect(screen.getByTestId("context").textContent).toBe("none");
  });
});
