import { Head } from "@inertiajs/react";

import { ExecutingNavigationIcon } from "@/components/navigation-icons";
import { ListPageHeader } from "@/components/shell/list-page-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import {
  Statistic,
  StatisticGrid,
  StatisticLabel,
  StatisticValue,
} from "@/components/ui/statistic";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Duration } from "@/components/duration";
import { TableEmpty } from "@/components/data-table/table-empty";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { cn } from "@/lib/utils";
import type { ExecutingJobsPageProps, RunningJob } from "@/types/executing";

function ExecutingIndex({ horizon, executing }: ExecutingJobsPageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const autoRefreshEnabled = autoLoad && horizon.pollInterval > 0;

  usePageRefresh(horizon.pollInterval, ["executing"], autoRefreshEnabled);

  return (
    <>
      <Head title="Executing Now" />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        {executing.available && executing.nodeSummary.length > 0 ? (
          <Card>
            <CardContent className="p-0">
              <StatisticGrid className="sm:grid-cols-2 shell:grid-cols-4">
                {executing.nodeSummary.map((node) => (
                  <Statistic key={node.node}>
                    <StatisticLabel>{node.node}</StatisticLabel>
                    <StatisticValue>{node.count}</StatisticValue>
                  </Statistic>
                ))}
              </StatisticGrid>
            </CardContent>
          </Card>
        ) : null}

        <Card>
          <ListPageHeader title="Executing Now" />
          <CardContent className="p-0">
            {!executing.available ? (
              <Empty className="min-h-64">
                <EmptyHeader>
                  <EmptyMedia variant="icon">
                    <ExecutingNavigationIcon aria-hidden="true" />
                  </EmptyMedia>
                  <EmptyTitle>Executing jobs unavailable</EmptyTitle>
                  <EmptyDescription>
                    {executing.message ?? "Executing jobs could not be loaded."}
                  </EmptyDescription>
                </EmptyHeader>
              </Empty>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Job</TableHead>
                    <TableHead>Queue</TableHead>
                    <TableHead>Node</TableHead>
                    <TableHead>Supervisor</TableHead>
                    <TableHead>Elapsed</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {executing.jobs.length === 0 ? (
                    <TableEmpty
                      columns={6}
                      icon={ExecutingNavigationIcon}
                      title="No jobs executing"
                      description="Jobs will appear here while a worker is processing them."
                    />
                  ) : (
                    executing.jobs.map((job) => <ExecutingRow key={job.id} job={job} />)
                  )}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
}

function ExecutingRow({ job }: { job: RunningJob }) {
  return (
    <TableRow className={cn(job.overrunning && "bg-status-unavailable/5")}>
      <TableCell className="font-mono text-xs">{job.jobClass}</TableCell>
      <TableCell>{job.queue}</TableCell>
      <TableCell>{job.node}</TableCell>
      <TableCell>{job.supervisor ?? "—"}</TableCell>
      <TableCell>
        <Duration seconds={job.elapsedSeconds} showRawValue />
      </TableCell>
      <TableCell>
        {job.overrunning ? (
          <Badge variant="destructive">Overrunning</Badge>
        ) : (
          <Badge variant="processing">Running</Badge>
        )}
      </TableCell>
    </TableRow>
  );
}

export default ExecutingIndex;
