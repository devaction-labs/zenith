import { createContext, type ReactNode, useCallback, useContext, useMemo, useState } from "react";

export const REFRESH_RATE_OPTIONS = [1000, 2000, 5000, 15000] as const;
export type RefreshRateOption = (typeof REFRESH_RATE_OPTIONS)[number];
export type RefreshRateMs = RefreshRateOption | 0;

const STORAGE_KEY = "horizonRefreshRateMs";

export function isRefreshRateMs(value: number): value is RefreshRateMs {
  return value === 0 || (REFRESH_RATE_OPTIONS as readonly number[]).includes(value);
}

function storedRefreshRate(): RefreshRateMs | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);

    if (raw === null) {
      return null;
    }

    const parsed = Number(raw);

    return isRefreshRateMs(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

export function nearestRefreshRate(defaultIntervalMs: number): RefreshRateMs {
  if (defaultIntervalMs <= 0) {
    return 0;
  }

  return REFRESH_RATE_OPTIONS.reduce<RefreshRateOption>(
    (closest, option) =>
      Math.abs(option - defaultIntervalMs) < Math.abs(closest - defaultIntervalMs)
        ? option
        : closest,
    REFRESH_RATE_OPTIONS[0],
  );
}

type RefreshRateContextValue = {
  refreshRateMs: RefreshRateMs;
  setRefreshRateMs: (value: RefreshRateMs) => void;
};

const RefreshRateContext = createContext<RefreshRateContextValue | null>(null);

export function RefreshRateProvider({
  defaultIntervalMs,
  children,
}: {
  defaultIntervalMs: number;
  children: ReactNode;
}) {
  const [refreshRateMs, setRefreshRateMsState] = useState<RefreshRateMs>(
    () => storedRefreshRate() ?? nearestRefreshRate(defaultIntervalMs),
  );

  const setRefreshRateMs = useCallback((value: RefreshRateMs) => {
    setRefreshRateMsState(value);

    try {
      window.localStorage.setItem(STORAGE_KEY, String(value));
    } catch {}
  }, []);

  const value = useMemo(
    () => ({ refreshRateMs, setRefreshRateMs }),
    [refreshRateMs, setRefreshRateMs],
  );

  return <RefreshRateContext.Provider value={value}>{children}</RefreshRateContext.Provider>;
}

export function useRefreshRatePreference(): RefreshRateContextValue | null {
  return useContext(RefreshRateContext);
}

export function useEffectiveRefreshRate(fallbackIntervalMs: number): RefreshRateMs {
  const context = useContext(RefreshRateContext);

  return context?.refreshRateMs ?? nearestRefreshRate(fallbackIntervalMs);
}
