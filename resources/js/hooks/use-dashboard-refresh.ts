import { router, usePoll } from "@inertiajs/react";
import { useEffect, useRef } from "react";

import { trackBackgroundRefresh } from "@/lib/auto-refresh-status";

const dashboardProps = ["summary", "workload", "supervisors"];
export function usePageRefresh(
  interval: number,
  props: readonly string[],
  enabled: boolean,
  includeSharedProps = true,
) {
  const requestOptions = () =>
    trackBackgroundRefresh({
      only: [
        ...new Set([...props, ...(includeSharedProps ? ["horizon", "navigationCounts"] : [])]),
      ],
      preserveUrl: true,
      showProgress: false,
    });
  const requestOptionsRef = useRef(requestOptions);
  const wasPollingRef = useRef(enabled && interval > 0);

  requestOptionsRef.current = requestOptions;

  const poll = usePoll(interval, () => requestOptionsRef.current(), {
    autoStart: false,
    mode: "cancel",
  });
  const pollRef = useRef(poll);

  pollRef.current = poll;

  useEffect(() => {
    const controls = pollRef.current;
    const shouldPoll = enabled && interval > 0;

    if (shouldPoll) {
      if (!wasPollingRef.current) {
        router.reload(requestOptionsRef.current());
      }

      controls.start();
    } else {
      controls.stop();
    }

    wasPollingRef.current = shouldPoll;

    return () => controls.stop();
  }, [enabled, includeSharedProps, interval]);
}

export function useDashboardRefresh(interval: number, enabled: boolean, props = dashboardProps) {
  usePageRefresh(interval, props, enabled);
}
