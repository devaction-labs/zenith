import type { VisitOptions } from "@inertiajs/core";
import { useSyncExternalStore } from "react";

export type AutoRefreshStatus = "idle" | "refreshing" | "failed";

type Listener = () => void;

let activeCount = 0;
let failed = false;
const listeners = new Set<Listener>();

function currentStatus(): AutoRefreshStatus {
  if (activeCount > 0) {
    return "refreshing";
  }

  return failed ? "failed" : "idle";
}

function emit(): void {
  for (const listener of listeners) {
    listener();
  }
}

export function getAutoRefreshStatus(): AutoRefreshStatus {
  return currentStatus();
}

export function subscribeAutoRefreshStatus(listener: Listener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function useAutoRefreshStatus(): AutoRefreshStatus {
  return useSyncExternalStore(subscribeAutoRefreshStatus, getAutoRefreshStatus, () => "idle");
}

export function resetAutoRefreshStatusForTests(): void {
  activeCount = 0;
  failed = false;
  emit();
}

/**
 * Compose request options so automatic refresh lifecycle is visible as
 * idle → refreshing → idle|failed without toasting on each failure.
 */
export function trackBackgroundRefresh(options: VisitOptions): VisitOptions {
  let began = false;
  let settled = false;
  let outcome: "success" | "failure" | "cancel" | null = null;

  const begin = () => {
    if (began) {
      return;
    }

    began = true;
    activeCount += 1;
    emit();
  };

  const settle = () => {
    if (!began || settled) {
      return;
    }

    settled = true;
    activeCount = Math.max(0, activeCount - 1);

    if (outcome === "success") {
      failed = false;
    } else if (outcome === "failure") {
      failed = true;
    }

    emit();
  };

  const markFailure = () => {
    if (outcome === null) {
      outcome = "failure";
    }
  };

  return {
    ...options,
    onCancelToken: (token) => {
      begin();
      options.onCancelToken?.(token);
    },
    onStart: (visit) => {
      begin();
      options.onStart?.(visit);
    },
    onSuccess: (page) => {
      outcome = "success";
      options.onSuccess?.(page);
    },
    onError: (errors) => {
      markFailure();
      options.onError?.(errors);
    },
    onNetworkError: (error) => {
      markFailure();

      if (options.onNetworkError) {
        return options.onNetworkError(error);
      }

      // Prevent Inertia's default network-error rejection for automatic refreshes.
      return false;
    },
    onHttpException: (response) => {
      markFailure();

      if (options.onHttpException) {
        return options.onHttpException(response);
      }

      // Keep automatic refresh failures restrained; the control shows the status.
      return false;
    },
    onCancel: () => {
      if (outcome === null) {
        outcome = "cancel";
      }

      settle();
      options.onCancel?.();
    },
    onFinish: (visit) => {
      settle();
      options.onFinish?.(visit);
    },
  };
}
