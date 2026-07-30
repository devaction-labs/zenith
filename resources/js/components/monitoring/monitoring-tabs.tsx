import { Link, router } from "@inertiajs/react";

import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { Tabs } from "@/components/ui/tabs";
import { show as monitoringShow } from "@/generated/routes/horizon-new-dawn/monitoring";
import { useActiveTabQuery } from "@/hooks/use-active-tab-query";
import { formatCount } from "@/lib/format-count";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { urlWithCurrentQuery } from "@/lib/url-query";
import type { MonitoringStatus } from "@/types/monitoring";

export function MonitoringTabs({
  tag,
  status,
  horizonBaseUrl,
  trackedCount,
  failedCount,
}: {
  tag: string;
  status: MonitoringStatus;
  horizonBaseUrl: string;
  trackedCount: number;
  failedCount: number;
}) {
  const route = (nextStatus: MonitoringStatus) => {
    const destination = resolveHorizonRoute(
      monitoringShow({ tag: encodeURIComponent(tag), status: nextStatus }),
      horizonBaseUrl,
    ).url;

    return urlWithCurrentQuery(destination, {}, [
      "starting_at",
      "tab",
      "job",
      "queue",
      "sort",
      "direction",
    ]);
  };

  useActiveTabQuery(status);

  return (
    <Tabs value={status} className="min-w-0 flex-1 gap-0">
      <ResponsiveTabsHeader
        value={status}
        items={[
          {
            value: "jobs",
            label: "Recent Jobs",
            count: formatCount(trackedCount),
            render: <Link href={route("jobs")} prefetch preserveScroll preserveState />,
          },
          {
            value: "failed",
            label: "Failed Jobs",
            count: formatCount(failedCount),
            render: <Link href={route("failed")} prefetch preserveScroll preserveState />,
          },
        ]}
        ariaLabel="Monitored tag job status"
        separatedFromHeader
        onValueChange={(value) => {
          if (value !== null && value !== status) {
            router.visit(route(value), { preserveScroll: true, preserveState: true });
          }
        }}
        className="w-full"
        tabsListClassName="relative w-full px-4 before:pointer-events-none before:absolute before:inset-x-0 before:bottom-0 before:hidden before:h-px before:bg-separator"
        triggerClassName="py-2.5"
      />
    </Tabs>
  );
}
