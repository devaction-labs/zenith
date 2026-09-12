import { Head, router } from "@inertiajs/react";
import { LoaderCircleIcon, PauseIcon, PlayIcon, PlusIcon } from "lucide-react";
import { useState } from "react";

import { DynamicCronActionsMenu } from "@/components/schedule/dynamic-cron-actions-menu";
import { DynamicCronDialog } from "@/components/schedule/dynamic-cron-dialog";
import { RunHistorySparkline } from "@/components/schedule/run-history-sparkline";
import { ListPageHeader } from "@/components/shell/list-page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { TableEmpty } from "@/components/data-table/table-empty";
import {
  destroy as resumeSchedule,
  store as pauseSchedule,
} from "@/generated/routes/zenith/schedule/pause";
import { store as runSchedule } from "@/generated/routes/zenith/schedule/run";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { HorizonPageProps } from "@/types/page";
import type { ScheduleEvent, SchedulePageProps } from "@/types/schedule";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function SchedulePauseControl({
  horizonBaseUrl,
  schedulePaused,
}: {
  horizonBaseUrl: string;
  schedulePaused: boolean;
}) {
  const [working, setWorking] = useState(false);
  const options = {
    preserveScroll: true,
    onStart: () => setWorking(true),
    onFinish: () => setWorking(false),
  };

  if (schedulePaused) {
    return (
      <Button
        size="sm"
        variant="outline"
        disabled={working}
        onClick={() =>
          router.delete(resolveHorizonRoute(resumeSchedule(), horizonBaseUrl).url, options)
        }
      >
        {working ? <LoaderCircleIcon className="animate-spin" /> : <PlayIcon />}
        Resume scheduler
      </Button>
    );
  }

  return (
    <Button
      size="sm"
      variant="outline"
      disabled={working}
      onClick={() =>
        router.post(resolveHorizonRoute(pauseSchedule(), horizonBaseUrl).url, {}, options)
      }
    >
      {working ? <LoaderCircleIcon className="animate-spin" /> : <PauseIcon />}
      Pause scheduler
    </Button>
  );
}

function ScheduleIndex({
  horizon,
  events,
  canRun,
  dynamicCronAllowedClasses,
}: HorizonPageProps & SchedulePageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const abilities = useHorizonAbilities();
  const allowRun = canRun && abilities.manageSchedule;
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingCron, setEditingCron] = useState<ScheduleEvent | null>(null);

  usePageRefresh(horizon.pollInterval, ["events", "canRun"], autoLoad);

  const openCreateDialog = () => {
    setEditingCron(null);
    setDialogOpen(true);
  };

  const openEditDialog = (event: ScheduleEvent) => {
    setEditingCron(event);
    setDialogOpen(true);
  };

  return (
    <>
      <Head title="Schedule" />
      <Card>
        <ListPageHeader
          title="Schedule"
          separated={false}
          actions={
            abilities.manageSchedule ? (
              <div className="flex items-center gap-2">
                <SchedulePauseControl
                  horizonBaseUrl={horizon.baseUrl}
                  schedulePaused={horizon.schedulePaused === true}
                />
                <Button size="sm" onClick={openCreateDialog}>
                  <PlusIcon />
                  Create dynamic cron
                </Button>
              </div>
            ) : null
          }
        />
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Expression</TableHead>
                <TableHead>Event</TableHead>
                <TableHead>Next run</TableHead>
                <TableHead>Flags</TableHead>
                <TableHead>History</TableHead>
                {allowRun ? <TableHead className="text-right">Actions</TableHead> : null}
              </TableRow>
            </TableHeader>
            <TableBody>
              {events.length === 0 ? (
                <TableEmpty
                  columns={allowRun ? 6 : 5}
                  title="No scheduled events"
                  description="Register events on the Laravel scheduler or create dynamic crons to see them here."
                />
              ) : (
                events.map((event) => (
                  <TableRow key={event.id}>
                    <TableCell className="font-mono text-xs">{event.expression}</TableCell>
                    <TableCell>
                      <div className="flex flex-col gap-1">
                        <span className="break-all">{event.description}</span>
                        {event.command ? (
                          <span className="break-all text-muted-foreground text-xs">
                            {event.command}
                          </span>
                        ) : null}
                      </div>
                    </TableCell>
                    <TableCell>
                      {event.nextRunAt ? dateFormatter.format(event.nextRunAt * 1000) : "—"}
                      {event.timezone ? (
                        <div className="text-muted-foreground text-xs">{event.timezone}</div>
                      ) : null}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        {event.runtimeEditable ? <Badge variant="secondary">Dynamic</Badge> : null}
                        {event.paused ? <Badge variant="warning">Paused</Badge> : null}
                        {event.withoutOverlapping ? <Badge>No overlap</Badge> : null}
                        {event.overlapping ? <Badge variant="retry">Running</Badge> : null}
                        {event.onOneServer ? <Badge>One server</Badge> : null}
                        {event.evenInMaintenanceMode ? <Badge>Maintenance</Badge> : null}
                        {event.runInBackground ? <Badge>Background</Badge> : null}
                      </div>
                    </TableCell>
                    <TableCell>
                      <RunHistorySparkline history={event.history} />
                    </TableCell>
                    {allowRun ? (
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                              router.post(
                                resolveHorizonRoute(runSchedule(event.id), horizon.baseUrl).url,
                              )
                            }
                          >
                            Run
                          </Button>
                          {event.dynamicCronId !== null ? (
                            <DynamicCronActionsMenu
                              name={event.description}
                              cronId={event.dynamicCronId}
                              paused={event.paused}
                              horizonBaseUrl={horizon.baseUrl}
                              onEdit={() => openEditDialog(event)}
                            />
                          ) : null}
                        </div>
                      </TableCell>
                    ) : null}
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {abilities.manageSchedule ? (
        <DynamicCronDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          horizonBaseUrl={horizon.baseUrl}
          cron={editingCron}
          allowedClasses={dynamicCronAllowedClasses}
        />
      ) : null}
    </>
  );
}

export default ScheduleIndex;
