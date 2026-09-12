import { Head, Link } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";

import { StatusBadge } from "@/components/dashboard/status-badge";
import { DetailList, DetailListItem } from "@/components/detail-list";
import { SupervisorScaleControl } from "@/components/supervisors/supervisor-scale-control";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { show as queueShow } from "@/generated/routes/zenith/queues";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { formatDuration } from "@/lib/format-duration";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { HorizonStatus as HorizonStatusValue } from "@/types/dashboard";
import type { SupervisorDetails, SupervisorDetailsPageProps } from "@/types/supervisors";

const numberFormatter = new Intl.NumberFormat();

function SupervisorShow({
  horizon,
  supervisorDetails,
  supervisorScaleBounds,
}: SupervisorDetailsPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(horizon.pollInterval, ["supervisorDetails"], autoLoad);

  if (!supervisorDetails.available || supervisorDetails.supervisor === null) {
    return (
      <>
        <Head title="Supervisor" />
        <Alert variant="destructive">
          <TriangleAlertIcon aria-hidden="true" />
          <AlertTitle>Supervisor unavailable</AlertTitle>
          <AlertDescription>
            {supervisorDetails.message ?? "Horizon supervisor details are currently unavailable."}
          </AlertDescription>
        </Alert>
      </>
    );
  }

  const supervisor = supervisorDetails.supervisor;

  return (
    <>
      <Head title={supervisor.name} />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <Card>
          <CardHeader>
            <CardTitle className="truncate" title={supervisor.id}>
              {supervisor.name}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <PropertyList properties={identityProperties(supervisor, horizon.baseUrl)} />
          </CardContent>
        </Card>

        {supervisor.warnings.map((warning) => (
          <Alert variant="warning" key={`${warning.title}:${warning.description}`}>
            <TriangleAlertIcon aria-hidden="true" />
            <AlertTitle>{warning.title}</AlertTitle>
            <AlertDescription>{warning.description}</AlertDescription>
          </Alert>
        ))}

        <div className="grid gap-[7px] min-[1140px]:gap-3.5 lg:grid-cols-2">
          <PolicyCard title="Scaling Policy" properties={scalingProperties(supervisor)}>
            <CardFooter className="flex-col items-stretch gap-2.5 border-t pt-[7px] min-[1140px]:pt-3.5">
              <SupervisorScaleControl
                horizonBaseUrl={horizon.baseUrl}
                supervisorId={supervisor.id}
                processes={supervisor.processes}
                bounds={supervisorScaleBounds}
                balance={supervisor.balance}
                scalable={supervisor.status === "running" || supervisor.status === "paused"}
              />
            </CardFooter>
          </PolicyCard>
          <PolicyCard title="Worker Policy" properties={workerProperties(supervisor)} />
        </div>
      </div>
    </>
  );
}

type Property = {
  label: string;
  value: React.ReactNode;
};

function identityProperties(supervisor: SupervisorDetails, horizonBaseUrl: string): Property[] {
  const status = supervisorStatus(supervisor.status);

  return [
    {
      label: "Status",
      value: <StatusBadge status={status} />,
    },
    { label: "Master", value: supervisor.master },
    { label: "PID", value: supervisor.pid?.toString() ?? "—" },
    { label: "Connection", value: supervisor.connection },
    {
      label: "Queues",
      value:
        supervisor.queues.length > 0 ? (
          <span className="inline-flex flex-wrap">
            {supervisor.queues.map((queue, index) => (
              <span key={queue}>
                {index > 0 ? ", " : null}
                <Link
                  className="text-foreground underline decoration-foreground/40 underline-offset-4 transition-colors hover:text-primary hover:decoration-primary"
                  href={
                    resolveHorizonRoute(queueShow(encodeURIComponent(queue)), horizonBaseUrl).url
                  }
                  prefetch
                >
                  {queue}
                </Link>
              </span>
            ))}
          </span>
        ) : (
          "—"
        ),
    },
    { label: "Current processes", value: numberFormatter.format(supervisor.processes) },
  ];
}

function scalingProperties(supervisor: SupervisorDetails): Property[] {
  return [
    { label: "Balance strategy", value: namedValue(supervisor.balance) },
    { label: "Auto-scaling strategy", value: namedValue(supervisor.autoScalingStrategy) },
    { label: "Minimum processes", value: numberValue(supervisor.minProcesses) },
    { label: "Maximum processes", value: numberValue(supervisor.maxProcesses) },
    { label: "Balance cooldown", value: seconds(supervisor.balanceCooldown) },
    { label: "Maximum shift", value: numberValue(supervisor.balanceMaxShift) },
  ];
}

function workerProperties(supervisor: SupervisorDetails): Property[] {
  return [
    { label: "Memory", value: supervisor.memory === null ? "—" : `${supervisor.memory} MB` },
    { label: "Timeout", value: seconds(supervisor.timeout) },
    { label: "Retry after", value: seconds(supervisor.retryAfter) },
    {
      label: "Tries",
      value:
        supervisor.maxTries === null
          ? "—"
          : supervisor.maxTries === 0
            ? "Unlimited unless defined by the job"
            : numberFormatter.format(supervisor.maxTries),
    },
    { label: "Backoff", value: backoff(supervisor.backoff) },
    { label: "Maximum jobs", value: maximum(supervisor.maxJobs) },
    { label: "Maximum lifetime", value: maximumDuration(supervisor.maxTime) },
    { label: "Sleep", value: seconds(supervisor.sleep) },
    { label: "Rest", value: seconds(supervisor.rest) },
    {
      label: "Force in maintenance",
      value: supervisor.force === null ? "—" : supervisor.force ? "Enabled" : "Disabled",
    },
    { label: "Niceness", value: numberValue(supervisor.nice) },
  ];
}

function PolicyCard({
  title,
  properties,
  children,
}: {
  title: string;
  properties: Property[];
  children?: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        <PropertyList properties={properties} />
      </CardContent>
      {children}
    </Card>
  );
}

function PropertyList({ properties }: { properties: Property[] }) {
  return (
    <DetailList className="text-sm">
      {properties.map((property, index) => (
        <DetailListItem
          key={property.label}
          label={property.label}
          bordered={index > 0}
          valueClassName="break-words"
        >
          {property.value}
        </DetailListItem>
      ))}
    </DetailList>
  );
}

function supervisorStatus(status: string): HorizonStatusValue {
  if (status === "running" || status === "paused" || status === "unavailable") {
    return status;
  }

  return "inactive";
}

function namedValue(value: string | null): string {
  if (value === null) {
    return "—";
  }

  if (value === "off") {
    return "Disabled";
  }

  return value.charAt(0).toUpperCase() + value.slice(1);
}

function numberValue(value: number | null): string {
  return value === null ? "—" : numberFormatter.format(value);
}

function seconds(value: number | null): string {
  if (value === null) {
    return "—";
  }

  return formatDuration(value, "precise");
}

function backoff(value: number | number[] | null): string {
  if (Array.isArray(value)) {
    return value.length === 0 ? "—" : value.map((secondsValue) => seconds(secondsValue)).join(", ");
  }

  return seconds(value);
}

function maximum(value: number | null): string {
  if (value === null) {
    return "—";
  }

  return value === 0 ? "Unlimited" : numberFormatter.format(value);
}

function maximumDuration(value: number | null): string {
  if (value === null) {
    return "—";
  }

  return value === 0 ? "Unlimited" : seconds(value);
}

export default SupervisorShow;
