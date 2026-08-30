import { Head, InfiniteScroll } from "@inertiajs/react";
import { useRef } from "react";

import { ListPageHeader } from "@/components/shell/list-page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { TableEmpty } from "@/components/data-table/table-empty";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { TriangleAlertIcon } from "lucide-react";

type AuditEvent = {
  id: number;
  occurredAt: number | null;
  action: string;
  route: string;
  userId: string | null;
  ip: string | null;
  context: Record<string, string | number | boolean | null>;
};

type AuditPageProps = {
  events: {
    data: AuditEvent[];
    available: boolean;
    message: string | null;
  };
};

const dateFormatter = new Intl.DateTimeFormat("sv-SE", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

function AuditIndex({ events }: AuditPageProps) {
  const bodyRef = useRef<HTMLTableSectionElement>(null);

  return (
    <>
      <Head title="Audit" />
      <Card>
        <ListPageHeader title="Audit" separated={false} />
        <CardContent className="p-0">
          {!events.available ? (
            <Alert variant="warning" className="m-4">
              <TriangleAlertIcon aria-hidden="true" />
              <AlertTitle>Audit log unavailable</AlertTitle>
              <AlertDescription>
                {events.message ?? "Mutation audit events could not be loaded."}
              </AlertDescription>
            </Alert>
          ) : (
            <InfiniteScroll data="events" itemsElement={bodyRef} onlyNext preserveUrl>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Action</TableHead>
                    <TableHead>User</TableHead>
                    <TableHead>IP</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody ref={bodyRef}>
                  {events.data.length === 0 ? (
                    <TableEmpty
                      columns={4}
                      title="No audited mutations"
                      description="Successful dashboard mutations will appear here."
                    />
                  ) : (
                    events.data.map((event) => (
                      <TableRow key={event.id}>
                        <TableCell>
                          {event.occurredAt
                            ? dateFormatter.format(event.occurredAt * 1000)
                            : "—"}
                        </TableCell>
                        <TableCell className="font-mono text-xs">{event.route}</TableCell>
                        <TableCell>{event.userId ?? "—"}</TableCell>
                        <TableCell>{event.ip ?? "—"}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </InfiniteScroll>
          )}
        </CardContent>
      </Card>
    </>
  );
}

export default AuditIndex;
