import { Head, Link, router } from "@inertiajs/react";

import { DetailList, DetailListItem } from "@/components/detail-list";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { show as workflowShow } from "@/generated/routes/zenith/workflows";
import { store as cancelWorkflow } from "@/generated/routes/zenith/workflows/cancel";
import { store as retryWorkflow } from "@/generated/routes/zenith/workflows/retry";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { useAutoLoadPreference } from "@/layouts/horizon-layout";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import { workflowStatusVariant } from "@/pages/workflows/status";
import type { HorizonPageProps } from "@/types/page";
import type { WorkflowDetailPageProps } from "@/types/workflows";

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

const linkClassName = "underline decoration-foreground/40 underline-offset-4";

function WorkflowShow({ horizon, workflow }: HorizonPageProps & WorkflowDetailPageProps) {
  const { autoLoad } = useAutoLoadPreference();
  const abilities = useHorizonAbilities();

  usePageRefresh(horizon.pollInterval, ["workflow"], autoLoad);

  const failedStep = workflow.steps.find((step) => step.status === "failed");
  const workflowUrl = (id: string) => resolveHorizonRoute(workflowShow(id), horizon.baseUrl).url;

  return (
    <>
      <Head title={workflow.name ?? "Workflow"} />
      <div className="flex flex-col gap-[7px] min-[1140px]:gap-3.5">
        <Card>
          <CardHeader className="flex flex-row justify-between gap-4">
            <CardTitle className="truncate">{workflow.name ?? workflow.id}</CardTitle>
            <CardAction className="flex gap-2">
              {workflow.cancellable && abilities.manageWorkflows ? (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() =>
                    router.post(
                      resolveHorizonRoute(cancelWorkflow(workflow.id), horizon.baseUrl).url,
                    )
                  }
                >
                  Cancel
                </Button>
              ) : null}
              {workflow.retryable && failedStep && abilities.manageWorkflows ? (
                <Button
                  size="sm"
                  onClick={() =>
                    router.post(
                      resolveHorizonRoute(retryWorkflow(workflow.id), horizon.baseUrl).url,
                      {
                        step: failedStep.name,
                      },
                    )
                  }
                >
                  Retry failed
                </Button>
              ) : null}
            </CardAction>
          </CardHeader>
          <CardContent className="p-0">
            <DetailList>
              <DetailListItem label="Status">
                <Badge variant={workflowStatusVariant(workflow.status)}>{workflow.status}</Badge>
              </DetailListItem>
              <DetailListItem label="ID" scrollable>
                {workflow.id}
              </DetailListItem>
              {workflow.parentId ? (
                <DetailListItem label="Parent workflow" scrollable>
                  <Link className={linkClassName} href={workflowUrl(workflow.parentId)}>
                    {workflow.parentId}
                  </Link>
                </DetailListItem>
              ) : null}
              <DetailListItem label="Created">
                {workflow.createdAt ? dateFormatter.format(workflow.createdAt * 1000) : "—"}
              </DetailListItem>
              <DetailListItem label="Finished">
                {workflow.finishedAt ? dateFormatter.format(workflow.finishedAt * 1000) : "—"}
              </DetailListItem>
            </DetailList>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Steps</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Step</TableHead>
                  <TableHead>Depends on</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Attempts</TableHead>
                  <TableHead>Output</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {workflow.steps.map((step) => (
                  <TableRow key={step.name}>
                    <TableCell>
                      <div className="flex flex-col gap-1">
                        <span>{step.name}</span>
                        {step.nested && step.childId ? (
                          <Link
                            className={`text-muted-foreground text-xs ${linkClassName}`}
                            href={workflowUrl(step.childId)}
                          >
                            Nested workflow
                          </Link>
                        ) : (
                          <span className="break-all text-muted-foreground text-xs">
                            {step.jobClass}
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>{step.deps.length > 0 ? step.deps.join(", ") : "—"}</TableCell>
                    <TableCell>
                      <Badge variant={workflowStatusVariant(step.status)}>{step.status}</Badge>
                      {step.cascade ? <Badge className="ml-1">Cascade</Badge> : null}
                    </TableCell>
                    <TableCell className="tabular-nums">{step.attempts}</TableCell>
                    <TableCell className="max-w-xs break-all text-xs">
                      {step.error ?? (step.output ? JSON.stringify(step.output) : "—")}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {workflow.children.length > 0 ? (
          <Card>
            <CardHeader>
              <CardTitle>Nested workflows</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Steps</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {workflow.children.map((child) => (
                    <TableRow key={child.id}>
                      <TableCell>
                        <Link className={linkClassName} href={workflowUrl(child.id)}>
                          {child.name ?? child.id}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Badge variant={workflowStatusVariant(child.status)}>{child.status}</Badge>
                      </TableCell>
                      <TableCell className="tabular-nums">
                        {child.completedSteps}/{child.stepCount}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </>
  );
}

export default WorkflowShow;
