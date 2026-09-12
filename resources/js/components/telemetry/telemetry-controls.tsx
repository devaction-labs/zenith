import { useId } from "react";

import { Field, FieldLabel } from "@/components/ui/field";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  telemetryGroupByOptions,
  telemetryWindowOptions,
  type TelemetryGroupBy,
  type TelemetryWindow,
} from "@/types/telemetry";

export function TelemetryGroupBySelect({
  value,
  onValueChange,
}: {
  value: TelemetryGroupBy;
  onValueChange: (value: TelemetryGroupBy) => void;
}) {
  const triggerId = useId();

  return (
    <Field orientation="horizontal" className="w-auto items-center gap-2">
      <FieldLabel htmlFor={triggerId} className="text-xs text-muted-foreground">
        Group by
      </FieldLabel>
      <Select
        items={telemetryGroupByOptions}
        value={value}
        onValueChange={(nextValue) => onValueChange(nextValue as TelemetryGroupBy)}
      >
        <SelectTrigger id={triggerId} size="sm">
          <SelectValue />
        </SelectTrigger>
        <SelectContent alignItemWithTrigger={false} listLabel="Group by options">
          <SelectGroup>
            {telemetryGroupByOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    </Field>
  );
}

export function TelemetryWindowSelect({
  value,
  onValueChange,
}: {
  value: TelemetryWindow;
  onValueChange: (value: TelemetryWindow) => void;
}) {
  const triggerId = useId();

  return (
    <Field orientation="horizontal" className="w-auto items-center gap-2">
      <FieldLabel htmlFor={triggerId} className="text-xs text-muted-foreground">
        Window
      </FieldLabel>
      <Select
        items={telemetryWindowOptions}
        value={value}
        onValueChange={(nextValue) => onValueChange(nextValue as TelemetryWindow)}
      >
        <SelectTrigger id={triggerId} size="sm">
          <SelectValue />
        </SelectTrigger>
        <SelectContent alignItemWithTrigger={false} listLabel="Window options">
          <SelectGroup>
            {telemetryWindowOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    </Field>
  );
}
