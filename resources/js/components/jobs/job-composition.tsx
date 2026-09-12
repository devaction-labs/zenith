import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { JobComposition } from "@/types/jobs";

export function JobCompositionPanel({ composition }: { composition?: JobComposition }) {
  if (!composition || (!composition.unique && !composition.encrypted && composition.chain.length === 0)) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Composition</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          {composition.unique ? <Badge>Unique</Badge> : null}
          {composition.encrypted ? <Badge>Encrypted</Badge> : null}
        </div>
        {composition.chain.length > 0 ? (
          <ol className="list-decimal space-y-1 pl-5 text-sm">
            {composition.chain.map((step, index) => (
              <li key={`${step.class}-${index}`} className="break-all" title={step.class}>
                {step.class}
              </li>
            ))}
          </ol>
        ) : null}
      </CardContent>
    </Card>
  );
}
