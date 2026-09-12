import { Head, Link } from "@inertiajs/react";

import { ListPageHeader } from "@/components/shell/list-page-header";
import { Badge } from "@/components/ui/badge";
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
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { TriangleAlertIcon } from "lucide-react";
import { show as workflowShow } from "@/generated/routes/zenith/workflows";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { workflowStatusVariant } from "@/pages/workflows/status";
import type { HorizonPageProps } from "@/types/page";
import type { WorkflowsPageProps } from "@/types/workflows";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function WorkflowsIndex({ horizon, available, workflows }: HorizonPageProps & WorkflowsPageProps) {
  const { autoLoad } = useAutoLoadPreference();

  usePageRefresh(horizon.pollInterval, ["workflows", "available"], autoLoad);

  return (
    <>
      <Head title="Workflows" />
      <Card>
        <ListPageHeader title="Workflows" separated={false} />
        <CardContent className="p-0">
          {!available ? (
            <Alert variant="warning" className="m-4">
              <TriangleAlertIcon aria-hidden="true" />
              <AlertTitle>Workflows unavailable</AlertTitle>
              <AlertDescription>
                Run the package migrations to store durable workflow DAGs.
              </AlertDescription>
            </Alert>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Steps</TableHead>
                  <TableHead>Created</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {workflows.length === 0 ? (
                  <TableEmpty
                    columns={4}
                    title="No workflows"
                    description="Dispatch a WorkflowDefinition to see it here."
                  />
                ) : (
                  workflows.map((workflow) => (
                    <TableRow key={workflow.id}>
                      <TableCell>
                        <Link
                          className="underline decoration-foreground/40 underline-offset-4"
                          href={resolveHorizonRoute(workflowShow(workflow.id), horizon.baseUrl).url}
                          prefetch
                        >
                          {workflow.name ?? workflow.id}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Badge variant={workflowStatusVariant(workflow.status)}>
                          {workflow.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {workflow.completedSteps}/{workflow.stepCount}
                      </TableCell>
                      <TableCell>
                        {workflow.createdAt ? dateFormatter.format(workflow.createdAt * 1000) : "—"}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </>
  );
}

export default WorkflowsIndex;
