import { router, usePoll } from "@inertiajs/react";
import { useCallback, useEffect, useRef, useState } from "react";

import { trackBackgroundRefresh } from "@/lib/auto-refresh-status";

const emptyProps: readonly string[] = [];
const firstPageSize = 50;
const infiniteScrollMergeIntentHeader = "X-Inertia-Infinite-Scroll-Merge-Intent";

type EntryReloadMode = "replace" | "prepend";

export function useAutoLoad({
  enabled,
  prop,
  interval,
  cursor = "starting_at",
  listRevision,
  additionalProps = emptyProps,
  includeSharedProps = true,
  scope = "default",
  polling = true,
  loadedItemCount,
}: {
  enabled: boolean;
  prop: string;
  interval: number;
  cursor?: string;
  listRevision?: string;
  additionalProps?: readonly string[];
  includeSharedProps?: boolean;
  scope?: string;
  polling?: boolean;
  loadedItemCount: number;
}) {
  const listRevisionRef = useRef(listRevision);
  const baselineRevisionRef = useRef(listRevision);
  const scopeRef = useRef(scope);
  const browsingHistoryRef = useRef(loadedItemCount > firstPageSize);
  const pollCancelRef = useRef<(() => void) | null>(null);
  const [hasNewEntries, setHasNewEntries] = useState(false);

  useEffect(() => {
    listRevisionRef.current = listRevision;

    if (scopeRef.current !== scope) {
      scopeRef.current = scope;
      browsingHistoryRef.current = loadedItemCount > firstPageSize;
      baselineRevisionRef.current = listRevision;
      setHasNewEntries(false);

      return;
    }

    if (loadedItemCount > firstPageSize && !browsingHistoryRef.current) {
      browsingHistoryRef.current = true;
    }

    if (enabled) {
      baselineRevisionRef.current = listRevision;
      setHasNewEntries(false);

      return;
    }

    if (listRevision !== undefined && listRevision !== baselineRevisionRef.current) {
      setHasNewEntries(true);
    }
  }, [enabled, listRevision, loadedItemCount, scope]);

  const requestOptions = (entryReloadMode?: EntryReloadMode) => {
    let cancelRequest: (() => void) | null = null;
    const includeEntries = entryReloadMode !== undefined;

    return trackBackgroundRefresh({
      data: { [cursor]: undefined },
      only: Array.from(
        new Set([
          ...(includeEntries ? [prop] : []),
          ...(listRevision === undefined ? [] : ["listRevision"]),
          ...additionalProps,
          ...(enabled && includeSharedProps ? ["horizon", "navigationCounts"] : []),
        ]),
      ),
      ...(entryReloadMode === "replace" ? { reset: [prop] } : {}),
      ...(entryReloadMode === "prepend"
        ? { headers: { [infiniteScrollMergeIntentHeader]: "prepend" } }
        : {}),
      preserveUrl: true,
      showProgress: false,
      onCancelToken: (token: { cancel: () => void }) => {
        cancelRequest = token.cancel;
        pollCancelRef.current = cancelRequest;
      },
      onFinish: () => {
        if (pollCancelRef.current === cancelRequest) {
          pollCancelRef.current = null;
        }
      },
    });
  };

  const requestOptionsRef = useRef(requestOptions);
  const wasEnabledRef = useRef(enabled);

  requestOptionsRef.current = requestOptions;

  const cancelActivePoll = useCallback(() => {
    const cancel = pollCancelRef.current;

    pollCancelRef.current = null;
    cancel?.();
  }, []);

  const onBeforeNextPage = useCallback(() => {
    cancelActivePoll();

    if (!browsingHistoryRef.current) {
      browsingHistoryRef.current = true;
    }
  }, [cancelActivePoll]);

  const loadNewEntries = useCallback(() => {
    cancelActivePoll();

    const options = requestOptionsRef.current("replace");

    router.reload({
      ...options,
      onSuccess: (page) => {
        options.onSuccess?.(page);

        const refreshedRevision = page.props.listRevision;
        const baselineRevision =
          typeof refreshedRevision === "string" ? refreshedRevision : listRevisionRef.current;

        listRevisionRef.current = baselineRevision;
        baselineRevisionRef.current = baselineRevision;
        browsingHistoryRef.current = false;
        setHasNewEntries(false);
      },
    });
  }, [cancelActivePoll]);

  const poll = usePoll(
    interval,
    () =>
      requestOptionsRef.current(
        enabled ? (browsingHistoryRef.current ? "prepend" : "replace") : undefined,
      ),
    {
      autoStart: false,
      mode: "cancel",
    },
  );
  const pollRef = useRef(poll);

  pollRef.current = poll;

  useEffect(() => {
    const controls = pollRef.current;

    if (polling && interval > 0) {
      if (enabled && !wasEnabledRef.current) {
        router.reload(
          requestOptionsRef.current(browsingHistoryRef.current ? "prepend" : "replace"),
        );
      }

      controls.start();
    } else {
      controls.stop();
    }

    wasEnabledRef.current = enabled;

    return () => controls.stop();
  }, [enabled, interval, polling]);

  return {
    hasNewEntries: !enabled && hasNewEntries,
    loadNewEntries,
    onBeforeNextPage,
  };
}
