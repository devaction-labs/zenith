import { router } from "@inertiajs/react";
import { useCallback, useEffect, useRef } from "react";

import type { JobFilterCatalog } from "@/types/jobs";

const filterCatalogProps = ["filterCatalog"];
const minimumFreshnessWindow = 250;

export function useJobFilterCatalogRefresh(
  interval: number,
  filterCatalog: JobFilterCatalog | undefined,
) {
  const inFlightRef = useRef(false);
  const cancelRequestRef = useRef<VoidFunction | null>(null);
  const catalogRef = useRef(filterCatalog);
  const refreshedAtRef = useRef(filterCatalog === undefined ? 0 : Date.now());

  useEffect(() => {
    if (filterCatalog === undefined || catalogRef.current === filterCatalog) {
      return;
    }

    catalogRef.current = filterCatalog;
    refreshedAtRef.current = Date.now();
  }, [filterCatalog]);

  useEffect(
    () => () => {
      cancelRequestRef.current?.();
      cancelRequestRef.current = null;
    },
    [],
  );

  return useCallback(() => {
    const freshnessWindow = Math.max(interval, minimumFreshnessWindow);
    const refreshedRecently =
      refreshedAtRef.current > 0 && Date.now() - refreshedAtRef.current < freshnessWindow;

    if (inFlightRef.current || refreshedRecently) {
      return;
    }

    inFlightRef.current = true;
    let cancelRequest: VoidFunction | null = null;

    router.reload({
      only: filterCatalogProps,
      preserveUrl: true,
      showProgress: false,
      onCancelToken: (token) => {
        cancelRequest = token.cancel;
        cancelRequestRef.current = cancelRequest;
      },
      onSuccess: () => {
        refreshedAtRef.current = Date.now();
      },
      onFinish: () => {
        if (cancelRequestRef.current === cancelRequest) {
          cancelRequestRef.current = null;
        }

        inFlightRef.current = false;
      },
    });
  }, [interval]);
}
