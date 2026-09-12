import { usePage } from "@inertiajs/react";

import { FailedJobsActionsMenu } from "@/components/jobs/failed-job-actions";
import { PendingJobsActions } from "@/components/jobs/pending-jobs-actions";
import {
  TabbedResultsBody,
  TabbedResultsLayout,
  type TabbedResultsTab,
} from "@/components/tabbed-results-card";
import { TabsContent } from "@/components/ui/tabs";
import { index as failedJobsIndex } from "@/generated/routes/zenith/failed-jobs";
import { index as jobsIndex } from "@/generated/routes/zenith/jobs";
import { useResolvedNavigationCounts } from "@/hooks/use-navigation-counts";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { FailedJobsPageProps, JobListType, JobsPageProps } from "@/types/jobs";
import type { HorizonPageProps } from "@/types/page";

export type JobsTab = JobListType | "failed";

const tabs: Array<{ label: string; value: JobsTab }> = [
  { label: "Pending", value: "pending" },
  { label: "Failed", value: "failed" },
  { label: "Completed", value: "completed" },
  { label: "Silenced", value: "silenced" },
];

type JobsLayoutPageProps = Pick<HorizonPageProps, "meta"> & (JobsPageProps | FailedJobsPageProps);

export function JobsLayout({ children }: { children: React.ReactNode }) {
  const { props } = usePage<JobsLayoutPageProps>();
  const navigationCounts = useResolvedNavigationCounts();
  const activeTab = isJobsTab(props.meta.activeNavigation)
    ? props.meta.activeNavigation
    : "pending";
  const resolvedTabs: TabbedResultsTab<JobsTab>[] = tabs.map((tab) => ({
    ...tab,
    count: navigationCounts?.[tab.value],
    href: tabUrl(tab.value, props.horizon.baseUrl),
  }));

  return (
    <TabbedResultsLayout
      title="Jobs"
      activeTab={activeTab}
      tabs={resolvedTabs}
      tabListLabel="Job status"
      actions={jobsActions(props, activeTab)}
    >
      <TabsContent value={activeTab}>{children}</TabsContent>
    </TabbedResultsLayout>
  );
}

export function JobsPage({
  activeTab,
  search,
  searchLabel,
  searchPlaceholder,
  onSearchChange,
  filters,
  children,
}: {
  activeTab: JobsTab;
  search?: string;
  searchLabel?: string;
  searchPlaceholder?: string;
  onSearchChange?: (value: string) => void;
  filters?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <TabbedResultsBody
      contentHeading={`${tabs.find((tab) => tab.value === activeTab)?.label} jobs`}
      search={search}
      searchLabel={searchLabel}
      searchPlaceholder={searchPlaceholder}
      onSearchChange={onSearchChange}
      filters={filters}
    >
      {children}
    </TabbedResultsBody>
  );
}

function jobsActions(props: JobsLayoutPageProps, activeTab: JobsTab): React.ReactNode {
  if (activeTab === "pending" && "type" in props && props.type === "pending") {
    return (
      <PendingJobsActions
        horizonBaseUrl={props.horizon.baseUrl}
        counts={{
          ready: props.pendingCounts?.ready ?? null,
          delayed: props.pendingCounts?.delayed ?? null,
        }}
        disabled={!props.jobs.available || props.pendingCounts?.available !== true}
      />
    );
  }

  if (activeTab === "failed" && "actions" in props) {
    return (
      <FailedJobsActionsMenu
        horizonBaseUrl={props.horizon.baseUrl}
        hasFailedJobs={props.actions.hasFailedJobs}
        retryable={props.actions.retryable}
        retryUnavailableReason={props.actions.retryUnavailableReason}
        clearable={props.actions.clearable}
        clearUnavailableReason={props.actions.clearUnavailableReason}
      />
    );
  }

  return null;
}

function isJobsTab(value: HorizonPageProps["meta"]["activeNavigation"]): value is JobsTab {
  return tabs.some((tab) => tab.value === value);
}

function tabUrl(tab: JobsTab, horizonBaseUrl: string): string {
  const route = tab === "failed" ? failedJobsIndex() : jobsIndex(tab);

  return resolveHorizonRoute(route, horizonBaseUrl).url;
}
