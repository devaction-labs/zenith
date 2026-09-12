import { router } from "@inertiajs/react";
import { useEffect, useRef } from "react";

import { useEffectiveRefreshRate } from "@/hooks/use-refresh-rate";
import { trackBackgroundRefresh } from "@/lib/auto-refresh-status";

const dashboardProps = ["summary", "workload", "supervisors"];
export function usePageRefresh(
  interval: number,
  props: readonly string[],
  enabled: boolean,
  includeSharedProps = true,
) {
  const refreshRate = useEffectiveRefreshRate(interval);
  const requestOptions = () =>
    trackBackgroundRefresh({
      only: [
        ...new Set([...props, ...(includeSharedProps ? ["horizon", "navigationCounts"] : [])]),
      ],
      preserveUrl: true,
      showProgress: false,
    });
  const requestOptionsRef = useRef(requestOptions);
  const wasPollingRef = useRef(enabled && refreshRate > 0);

  requestOptionsRef.current = requestOptions;

  useEffect(() => {
    const shouldPoll = enabled && refreshRate > 0;

    if (!shouldPoll) {
      wasPollingRef.current = false;

      return;
    }

    if (!wasPollingRef.current) {
      router.reload(requestOptionsRef.current());
    }

    wasPollingRef.current = true;

    const poll = router.poll(refreshRate, () => requestOptionsRef.current(), {
      mode: "cancel",
    });

    return () => poll.destroy();
  }, [enabled, includeSharedProps, refreshRate]);
}

export function useDashboardRefresh(interval: number, enabled: boolean, props = dashboardProps) {
  usePageRefresh(interval, props, enabled);
}
