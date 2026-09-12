import { router } from "@inertiajs/react";
import { useEffect, useRef } from "react";

import { dashboard } from "@/generated/routes/zenith";
import { index as failedJobsIndex } from "@/generated/routes/zenith/failed-jobs";
import { index as jobsIndex } from "@/generated/routes/zenith/jobs";
import { resolveHorizonRoute } from "@/lib/horizon-route";

const NAVIGATION_PREFIX_TIMEOUT_MS = 1500;
const SEARCH_INPUT_SELECTOR = '[role="searchbox"]';

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  const tagName = target.tagName;

  if (tagName === "INPUT" || tagName === "TEXTAREA" || tagName === "SELECT") {
    return true;
  }

  if (target.isContentEditable) {
    return true;
  }

  const editableAncestor = target.closest("[contenteditable]");

  return editableAncestor !== null && editableAncestor.getAttribute("contenteditable") !== "false";
}

export function useGlobalShortcuts({
  baseUrl,
  onOpenHelp,
}: {
  baseUrl: string;
  onOpenHelp: () => void;
}) {
  const baseUrlRef = useRef(baseUrl);
  const onOpenHelpRef = useRef(onOpenHelp);

  baseUrlRef.current = baseUrl;
  onOpenHelpRef.current = onOpenHelp;

  useEffect(() => {
    let pendingNavigationPrefix = false;
    let prefixTimeoutId: number | null = null;

    function clearNavigationPrefix() {
      pendingNavigationPrefix = false;

      if (prefixTimeoutId !== null) {
        window.clearTimeout(prefixTimeoutId);
        prefixTimeoutId = null;
      }
    }

    function visit(definition: { url: string }) {
      router.visit(resolveHorizonRoute(definition, baseUrlRef.current).url);
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
        return;
      }

      if (isEditableTarget(event.target)) {
        return;
      }

      if (pendingNavigationPrefix) {
        clearNavigationPrefix();

        if (event.key === "d") {
          visit(dashboard());
        } else if (event.key === "j") {
          visit(jobsIndex("pending"));
        } else if (event.key === "f") {
          visit(failedJobsIndex());
        }

        return;
      }

      if (event.key === "g") {
        pendingNavigationPrefix = true;
        prefixTimeoutId = window.setTimeout(clearNavigationPrefix, NAVIGATION_PREFIX_TIMEOUT_MS);

        return;
      }

      if (event.key === "/") {
        event.preventDefault();
        document.querySelector<HTMLElement>(SEARCH_INPUT_SELECTOR)?.focus();

        return;
      }

      if (event.key === "?") {
        onOpenHelpRef.current();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      clearNavigationPrefix();
    };
  }, []);
}
