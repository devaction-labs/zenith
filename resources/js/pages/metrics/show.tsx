import { Head, router } from "@inertiajs/react";
import { TriangleAlertIcon } from "lucide-react";

import { MetricChart } from "@/components/metrics/metric-chart";
import { TelemetryWindowSelect } from "@/components/telemetry/telemetry-controls";
import { PercentileChart } from "@/components/telemetry/percentile-chart";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { show as metricShow } from "@/generated/routes/zenith/metrics";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { MetricPreviewPageProps } from "@/types/metrics";
import type { TelemetryWindow } from "@/types/telemetry";

function MetricShow({
  horizon,
  type,
  name,
  preview,
  percentiles,
  percentilesWindow,
}: MetricPreviewPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(horizon.pollInterval, metricRefreshProps, autoLoad);

  const changePercentilesWindow = (windowValue: TelemetryWindow) => {
    const url = resolveHorizonRoute(
      metricShow({ type, slug: name }, { query: { window: windowValue } }),
      horizon.baseUrl,
    ).url;

    router.visit(url, { preserveScroll: true, preserveState: true, replace: true });
  };

  return (
    <>
      <Head title={`Metrics for ${name}`} />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        {!preview.available ? (
          <Alert variant="destructive">
            <TriangleAlertIcon aria-hidden="true" />
            <AlertTitle>Metrics unavailable</AlertTitle>
            <AlertDescription>
              {preview.message ?? "Metrics for this item are currently unavailable."}
            </AlertDescription>
          </Alert>
        ) : null}
        <Card>
          <CardHeader>
            <CardTitle className="truncate" title={`Execution time percentiles — ${name}`}>
              {`Execution time percentiles — ${name}`}
            </CardTitle>
            <CardAction>
              <TelemetryWindowSelect
                value={percentilesWindow}
                onValueChange={changePercentilesWindow}
              />
            </CardAction>
          </CardHeader>
          <CardContent className="px-0 pt-3 pb-2">
            {!percentiles.available ? (
              <Alert variant="destructive" className="mx-4 sm:mx-6">
                <TriangleAlertIcon aria-hidden="true" />
                <AlertTitle>Live percentiles unavailable</AlertTitle>
                <AlertDescription>
                  {percentiles.message ?? "Live percentiles are currently unavailable."}
                </AlertDescription>
              </Alert>
            ) : (
              <PercentileChart points={percentiles.points} />
            )}
          </CardContent>
        </Card>
        <MetricCard title={`Throughput — ${name}`}>
          <MetricChart kind="throughput" snapshots={preview.data} />
        </MetricCard>
        <MetricCard title={`Runtime — ${name}`}>
          <MetricChart kind="runtime" snapshots={preview.data} />
        </MetricCard>
      </div>
    </>
  );
}

function MetricCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="truncate" title={title}>
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="px-0 pt-3 pb-2">{children}</CardContent>
    </Card>
  );
}

const metricRefreshProps = ["preview", "percentiles"];

export default MetricShow;
