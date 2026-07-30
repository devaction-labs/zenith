import { router } from "@inertiajs/react";

export function prefetchQueueDetail(href: string) {
  if (router.getCached(href) === null) {
    router.prefetch(href);
  }
}

export function visitQueueDetail(href: string) {
  router.visit(href);
}
