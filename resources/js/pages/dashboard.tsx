import { Head } from "@inertiajs/react";

import { DashboardOverview } from "@/components/dashboard/dashboard-overview";
import { SupervisorsTable } from "@/components/dashboard/supervisors-table";
import { WorkloadTable } from "@/components/dashboard/workload-table";
import { Duration } from "@/components/duration";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Statistic,
  StatisticGrid,
  StatisticLabel,
  StatisticSupportingText,
  StatisticValue,
} from "@/components/ui/statistic";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import {
  index as batchesIndex,
  show as batchShow,
} from "@/generated/routes/horizon-new-dawn/batches";
import { index as failedJobsIndex } from "@/generated/routes/horizon-new-dawn/failed-jobs";
import { index as jobsIndex } from "@/generated/routes/horizon-new-dawn/jobs";
import { useDashboardRefresh } from "@/hooks/use-dashboard-refresh";
import { resolveProcessPollInterval, useProcessTransitions } from "@/hooks/use-process-transitions";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { DashboardPageProps } from "@/types/dashboard";

function Dashboard({ horizon, summary, workload, supervisors }: DashboardPageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const {
    hasPendingTransitions,
    onInstanceTransition,
    onInstanceTransitionFailure,
    onSupervisorTransition,
    onSupervisorTransitionFailure,
    transitions,
  } = useProcessTransitions(supervisors, horizon.pollInterval);
  const autoRefreshEnabled = autoLoad && horizon.pollInterval > 0;
  const fallbackPolling = !autoRefreshEnabled && hasPendingTransitions;
  const hasWorkload = workload.available && workload.items.length > 0;

  useDashboardRefresh(
    resolveProcessPollInterval(horizon.pollInterval),
    autoRefreshEnabled || fallbackPolling,
  );

  const route = (definition: { url: string }) =>
    resolveHorizonRoute(definition, horizon.baseUrl).url;

  return (
    <>
      <Head title="Dashboard" />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <DashboardOverview
          summary={summary}
          links={{
            pending: route(jobsIndex("pending")),
            failed: route(failedJobsIndex()),
            completed: route(jobsIndex("completed")),
            batches: route(batchesIndex()),
            batch: (id) => route(batchShow(id)),
          }}
        />

        <Card>
          <CardHeader>
            <CardTitle>Workload</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {hasWorkload ? (
              <StatisticGrid className="sm:grid-cols-2 shell:grid-cols-5">
                <WorkloadStat label="Total Processes" value={summary.processes} />
                <WorkloadStat
                  label="Max Wait Time"
                  value={
                    summary.maxWaitSeconds > 0 ? <Duration seconds={summary.maxWaitSeconds} /> : "—"
                  }
                />
                <WorkloadStat label="Max Runtime" value={summary.queueWithMaxRuntime ?? "—"} />
                <WorkloadStat
                  label="Max Throughput"
                  value={summary.queueWithMaxThroughput ?? "—"}
                />
                <WorkloadStat
                  label="Hourly Pressure"
                  value={summary.recentJobs}
                  valueTooltip="The number of jobs received by Horizon in the past hour."
                />
              </StatisticGrid>
            ) : null}
            <WorkloadTable
              workload={workload}
              horizonBaseUrl={horizon.baseUrl}
              queuePausing={horizon.capabilities?.queuePausing ?? false}
              timedQueuePausing={horizon.capabilities?.timedQueuePausing ?? false}
            />
          </CardContent>
        </Card>

        {supervisors ? (
          <SupervisorsTable
            autoRefreshEnabled={autoRefreshEnabled}
            supervisors={supervisors}
            horizonBaseUrl={horizon.baseUrl}
            transitions={transitions}
            onInstanceTransition={onInstanceTransition}
            onInstanceTransitionFailure={onInstanceTransitionFailure}
            onSupervisorTransition={onSupervisorTransition}
            onSupervisorTransitionFailure={onSupervisorTransitionFailure}
          />
        ) : null}
      </div>
    </>
  );
}

function WorkloadStat({
  label,
  value,
  detail,
  valueTooltip,
}: {
  label: string;
  value: React.ReactNode;
  detail?: string | null;
  valueTooltip?: string;
}) {
  const displayValue = valueTooltip ? (
    <Tooltip>
      <TooltipTrigger
        render={
          <span
            className="cursor-help rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            tabIndex={0}
          />
        }
      >
        {value}
      </TooltipTrigger>
      <TooltipContent side="top">{valueTooltip}</TooltipContent>
    </Tooltip>
  ) : (
    value
  );

  return (
    <Statistic>
      <StatisticLabel>{label}</StatisticLabel>
      <StatisticValue>{displayValue}</StatisticValue>
      {detail ? <StatisticSupportingText>({detail})</StatisticSupportingText> : null}
    </Statistic>
  );
}

export default Dashboard;
