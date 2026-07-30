import { Link, router } from "@inertiajs/react";

import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { Tabs } from "@/components/ui/tabs";
import { index as metricsIndex } from "@/generated/routes/horizon-new-dawn/metrics";
import { useActiveTabQuery } from "@/hooks/use-active-tab-query";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { MetricType } from "@/types/metrics";

export function MetricsTabs({
  type,
  horizonBaseUrl,
}: {
  type: MetricType;
  horizonBaseUrl: string;
}) {
  const route = (nextType: MetricType) =>
    resolveHorizonRoute(metricsIndex(nextType), horizonBaseUrl).url;

  useActiveTabQuery(type);

  return (
    <Tabs value={type} className="gap-0">
      <ResponsiveTabsHeader
        value={type}
        items={[
          {
            value: "jobs",
            label: "Jobs",
            render: <Link href={route("jobs")} prefetch preserveScroll preserveState />,
          },
          {
            value: "queues",
            label: "Queues",
            render: <Link href={route("queues")} prefetch preserveScroll preserveState />,
          },
        ]}
        ariaLabel="Metrics type"
        onValueChange={(value) => {
          if (value !== null && value !== type) {
            router.visit(route(value), { preserveScroll: true, preserveState: true });
          }
        }}
        className="w-full"
        tabsListClassName="relative w-full px-4 before:pointer-events-none before:absolute before:inset-x-0 before:bottom-0 before:h-px before:bg-separator"
        triggerClassName="py-2.5"
      />
    </Tabs>
  );
}
