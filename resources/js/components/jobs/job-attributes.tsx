import type { ReactNode } from "react";

import { DetailList, DetailListItem } from "@/components/detail-list";
import { Duration } from "@/components/duration";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { JobAttributes } from "@/types/jobs";

function formatBackoff(backoff: JobAttributes["backoff"]) {
  if (backoff === null) {
    return null;
  }

  return Array.isArray(backoff) ? backoff.join(", ") : String(backoff);
}

export function JobAttributesPanel({ attributes }: { attributes?: JobAttributes }) {
  if (!attributes) {
    return null;
  }

  const hasFlags =
    attributes.failOnTimeout || attributes.withoutRelations || attributes.deleteWhenMissingModels;
  const backoff = formatBackoff(attributes.backoff);
  const details: Array<{ label: string; value: ReactNode }> = [
    ...(attributes.tries !== null ? [{ label: "Max Tries", value: String(attributes.tries) }] : []),
    ...(backoff !== null ? [{ label: "Backoff", value: backoff }] : []),
    ...(attributes.timeout !== null
      ? [
          {
            label: "Timeout",
            value: <Duration seconds={attributes.timeout} format="precise" showRawValue />,
          },
        ]
      : []),
    ...(attributes.maxExceptions !== null
      ? [{ label: "Max Exceptions", value: String(attributes.maxExceptions) }]
      : []),
    ...(attributes.uniqueFor !== null
      ? [
          {
            label: "Unique For",
            value: <Duration seconds={attributes.uniqueFor} format="precise" showRawValue />,
          },
        ]
      : []),
    ...(attributes.debounceFor !== null
      ? [
          {
            label: "Debounce For",
            value: <Duration seconds={attributes.debounceFor} format="precise" showRawValue />,
          },
        ]
      : []),
    ...(attributes.debounceMaxWait !== null
      ? [
          {
            label: "Debounce Max Wait",
            value: <Duration seconds={attributes.debounceMaxWait} format="precise" showRawValue />,
          },
        ]
      : []),
    ...(attributes.queue !== null ? [{ label: "Queue Attribute", value: attributes.queue }] : []),
    ...(attributes.connection !== null
      ? [{ label: "Connection Attribute", value: attributes.connection }]
      : []),
    ...(attributes.delay !== null
      ? [
          {
            label: "Delay",
            value: <Duration seconds={attributes.delay} format="precise" showRawValue />,
          },
        ]
      : []),
    ...(attributes.routedQueue !== null
      ? [{ label: "Routed Queue", value: attributes.routedQueue }]
      : []),
    ...(attributes.routedConnection !== null
      ? [{ label: "Routed Connection", value: attributes.routedConnection }]
      : []),
  ];

  if (!hasFlags && details.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Attributes</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3 p-0">
        {hasFlags ? (
          <div className="flex flex-wrap gap-2 px-4 pt-4 sm:px-6">
            {attributes.failOnTimeout ? <Badge>Fails on timeout</Badge> : null}
            {attributes.withoutRelations ? <Badge>Without relations</Badge> : null}
            {attributes.deleteWhenMissingModels ? (
              <Badge>Deletes when models missing</Badge>
            ) : null}
          </div>
        ) : null}
        {details.length > 0 ? (
          <DetailList>
            {details.map((detail, index) => (
              <DetailListItem key={detail.label} label={detail.label} bordered={index > 0}>
                {detail.value}
              </DetailListItem>
            ))}
          </DetailList>
        ) : null}
      </CardContent>
    </Card>
  );
}
