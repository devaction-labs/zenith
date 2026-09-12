import { TriangleAlertIcon } from "lucide-react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { cn } from "@/lib/utils";
import type { QueueBypassWarning } from "@/types/dashboard";

function formatWindow(minutes: number) {
  return minutes === 1 ? "the past minute" : `the past ${minutes} minutes`;
}

export function QueueBypassBanner({
  warning,
  className,
}: {
  warning?: QueueBypassWarning;
  className?: string;
}) {
  if (!warning || (!warning.hasRecentFailovers && warning.bypassProneConnections.length === 0)) {
    return null;
  }

  return (
    <Alert variant="warning" className={cn(className)}>
      <TriangleAlertIcon aria-hidden="true" />
      <AlertTitle>Jobs may be bypassing Horizon</AlertTitle>
      <AlertDescription>
        {warning.hasRecentFailovers ? (
          <p>
            {warning.recentFailoverCount} {warning.recentFailoverCount === 1 ? "job" : "jobs"}{" "}
            failed over to a backup queue connection in{" "}
            {formatWindow(warning.recentFailoverWindowMinutes)}
            {warning.recentFailoverConnections.length > 0
              ? ` (${warning.recentFailoverConnections.join(", ")})`
              : null}
            . Failed-over jobs are invisible to Horizon and Zenith.
          </p>
        ) : null}
        {warning.bypassProneConnections.length > 0 ? (
          <p>
            These connections use a driver that can bypass Horizon entirely:{" "}
            {warning.bypassProneConnections.join(", ")}.
          </p>
        ) : null}
      </AlertDescription>
    </Alert>
  );
}
