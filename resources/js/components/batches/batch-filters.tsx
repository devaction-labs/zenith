import { FilterIcon } from "lucide-react";
import { useId } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Field, FieldGroup, FieldLabel } from "@/components/ui/field";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { BatchCreatedRange, BatchFilterValues } from "@/types/batches";

const ALL_OPTIONS = "__all__";

const createdOptions: Array<{ label: string; value: BatchCreatedRange }> = [
  { label: "Last hour", value: "hour" },
  { label: "Last 24 hours", value: "day" },
  { label: "Last 7 days", value: "week" },
  { label: "Last 30 days", value: "month" },
];

export function BatchFilters({
  queues,
  connections,
  values,
  onChange,
  attributionAvailable,
  attributionMessage,
}: {
  queues: readonly string[];
  connections: readonly string[];
  values: BatchFilterValues;
  onChange: (values: BatchFilterValues) => void;
  attributionAvailable: boolean;
  attributionMessage?: string | null;
}) {
  const activeFilterCount = Object.values(values).filter((value) => value !== null).length;

  return (
    <Dialog>
      <DialogTrigger
        render={
          <Button
            type="button"
            variant="action"
            size="icon-sm"
            className="relative text-muted-foreground"
            aria-label={
              activeFilterCount > 0
                ? `Filter batches, ${activeFilterCount} active`
                : "Filter batches"
            }
            title="Filter batches"
          />
        }
      >
        <FilterIcon />
        {activeFilterCount > 0 ? (
          <span
            aria-hidden="true"
            className="absolute top-0.5 right-0.5 size-2 rounded-full bg-primary"
          />
        ) : null}
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Filter batches</DialogTitle>
          <DialogDescription>
            {attributionAvailable
              ? "Narrow retained batches by queue, connection, or creation time. Historical batches without a recorded destination are inferred once from the queue configuration active when New Dawn first discovers them."
              : `Narrow retained batches by creation time. ${
                  attributionMessage ??
                  "Run the Horizon New Dawn migration to enable queue and connection filters."
                }`}
          </DialogDescription>
        </DialogHeader>
        <FieldGroup>
          {attributionAvailable ? (
            <>
              <BatchFilterSelect
                label="Queue"
                allLabel="All queues"
                options={queues.map((queue) => ({ label: queue, value: queue }))}
                value={values.queue}
                onValueChange={(queue) => onChange({ ...values, queue })}
              />
              <BatchFilterSelect
                label="Connection"
                allLabel="All connections"
                options={connections.map((connection) => ({
                  label: connection,
                  value: connection,
                }))}
                value={values.connection}
                onValueChange={(connection) => onChange({ ...values, connection })}
              />
            </>
          ) : null}
          <BatchFilterSelect
            label="Created"
            allLabel="Any time"
            options={createdOptions}
            value={values.created}
            onValueChange={(created) =>
              onChange({ ...values, created: created as BatchCreatedRange | null })
            }
          />
        </FieldGroup>
        <DialogFooter className="sm:justify-between">
          <Button
            type="button"
            variant="action"
            disabled={activeFilterCount === 0}
            onClick={() => onChange({ queue: null, connection: null, created: null })}
          >
            Clear filters
          </Button>
          <DialogClose render={<Button type="button" />}>Done</DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function BatchFilterSelect({
  label,
  allLabel,
  options,
  value,
  onValueChange,
}: {
  label: string;
  allLabel: string;
  options: readonly { label: string; value: string }[];
  value: string | null;
  onValueChange: (value: string | null) => void;
}) {
  const triggerId = useId();
  const items = [{ label: allLabel, value: ALL_OPTIONS }, ...options];

  return (
    <Field>
      <FieldLabel htmlFor={triggerId}>{label}</FieldLabel>
      <Select
        items={items}
        value={value ?? ALL_OPTIONS}
        onValueChange={(nextValue) => onValueChange(nextValue === ALL_OPTIONS ? null : nextValue)}
      >
        <SelectTrigger id={triggerId} className="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent alignItemWithTrigger={false} listLabel={`${label} options`}>
          <SelectGroup>
            {items.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    </Field>
  );
}
