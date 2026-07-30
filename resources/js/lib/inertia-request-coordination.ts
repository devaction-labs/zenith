import type { VisitOptions } from "@inertiajs/core";
import { router } from "@inertiajs/react";

const activePollRequests = new Set<VoidFunction>();

export function backgroundVisitOptions(_href: string, options: VisitOptions): VisitOptions {
  const isBackgroundGet =
    options.async === true &&
    (options.method === undefined || options.method === "get") &&
    options.prefetch !== true &&
    options.component == null;

  const resolved =
    !isBackgroundGet || "preserveUrl" in options ? options : { ...options, preserveUrl: true };

  if ((options as VisitOptions & { poll?: boolean }).poll !== true) {
    return resolved;
  }

  const onCancelToken = resolved.onCancelToken;
  const onFinish = resolved.onFinish;
  let cancelRequest: VoidFunction | null = null;

  return {
    ...resolved,
    onCancelToken: (token) => {
      cancelRequest = token.cancel;
      activePollRequests.add(cancelRequest);
      onCancelToken?.(token);
    },
    onFinish: (visit) => {
      if (cancelRequest !== null) {
        activePollRequests.delete(cancelRequest);
        cancelRequest = null;
      }

      onFinish?.(visit);
    },
  };
}

export function registerForegroundVisitCancellation() {
  const removeBeforeListener = router.on("before", (event) => {
    const visit = event.detail.visit;

    if (visit.prefetch || visit.async) {
      return;
    }

    const requests = Array.from(activePollRequests);
    activePollRequests.clear();

    requests.forEach((cancel) => cancel());
  });

  return removeBeforeListener;
}
