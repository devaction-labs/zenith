import { router } from "@inertiajs/react";
import { LoaderCircleIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { InputGroup, InputGroupInput } from "@/components/ui/input-group";
import { store as scaleSupervisor } from "@/generated/routes/zenith/supervisors/scale";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { SupervisorScaleBounds } from "@/types/supervisors";

export function SupervisorScaleControl({
  horizonBaseUrl,
  supervisorId,
  processes,
  bounds,
  balance,
  scalable,
}: {
  horizonBaseUrl: string;
  supervisorId: string;
  processes: number;
  bounds: SupervisorScaleBounds;
  balance: string | null;
  scalable: boolean;
}) {
  const abilities = useHorizonAbilities();
  const [value, setValue] = useState(String(processes));
  const [working, setWorking] = useState(false);

  if (!abilities.manageInstances) {
    return null;
  }

  const parsed = Number.parseInt(value, 10);
  const invalid = !Number.isInteger(parsed) || parsed < bounds.min || parsed > bounds.max;
  const balancingActive = balance !== "off";
  const inputId = `supervisor-scale-${supervisorId}`;

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (invalid || !scalable) {
      return;
    }

    const route = resolveHorizonRoute(
      scaleSupervisor(encodeURIComponent(supervisorId)),
      horizonBaseUrl,
    );

    router.post(
      route.url,
      { processes: parsed },
      {
        preserveScroll: true,
        onStart: () => setWorking(true),
        onFinish: () => setWorking(false),
      },
    );
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-2.5">
      <Field orientation="horizontal" className="items-center justify-between gap-4">
        <FieldLabel htmlFor={inputId}>Scale processes</FieldLabel>
        <div className="flex items-center gap-2">
          <InputGroup className="h-9 w-24">
            <InputGroupInput
              id={inputId}
              type="number"
              inputMode="numeric"
              min={bounds.min}
              max={bounds.max}
              required
              disabled={!scalable}
              value={value}
              onChange={(event) => setValue(event.target.value)}
            />
          </InputGroup>
          <Button type="submit" size="sm" disabled={working || invalid || !scalable}>
            {working ? <LoaderCircleIcon className="animate-spin" /> : null}
            {working ? "Scaling…" : "Scale"}
          </Button>
        </div>
      </Field>
      <FieldDescription>
        Allowed range is {bounds.min}–{bounds.max} processes.
        {balancingActive
          ? " Horizon auto-balancing may override this value while balancing is enabled."
          : null}
      </FieldDescription>
    </form>
  );
}
