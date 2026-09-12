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
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { JobFilterKey, JobFilterValues, JobListType } from "@/types/jobs";

export type { JobFilterKey, JobFilterValues };

const ALL_OPTIONS = "__all__";

export type JobFilterOption = {
  label: string;
  value: string;
};

const filterKeysByScope = {
  pending: ["job", "queue", "connection", "state", "tag"],
  failed: ["job", "queue", "connection", "tag"],
  completed: ["job", "queue", "connection", "tag"],
  silenced: ["job", "queue", "connection", "tag"],
} as const satisfies Record<JobListType | "failed", readonly JobFilterKey[]>;

const filterLabels = {
  job: { label: "Job class", allLabel: "All job classes" },
  queue: { label: "Queue", allLabel: "All queues" },
  connection: { label: "Connection", allLabel: "All connections" },
  state: { label: "State", allLabel: "All pending states" },
  tag: { label: "Tag", allLabel: "All tags" },
} satisfies Record<JobFilterKey, { label: string; allLabel: string }>;

const freeTextFilterKeys = new Set<JobFilterKey>(["tag"]);

export const emptyJobFilterValues: JobFilterValues = {
  job: null,
  queue: null,
  connection: null,
  state: null,
  tag: null,
};

export function jobFilterKeys(scope: JobListType | "failed"): readonly JobFilterKey[] {
  return filterKeysByScope[scope];
}

export function JobFilters({
  filterKeys,
  options,
  values,
  onIntent,
  onFilterChange,
  onClearFilters,
  description = "Narrow the complete retained job history using exact filters.",
}: {
  filterKeys: readonly JobFilterKey[];
  options: Partial<Record<JobFilterKey, readonly JobFilterOption[]>>;
  values: JobFilterValues;
  onIntent: () => void;
  onFilterChange: (filterKey: JobFilterKey, value: string | null) => void;
  onClearFilters: () => void;
  description?: string;
}) {
  const activeFilterCount = filterKeys.filter((filterKey) => values[filterKey] !== null).length;
  const label = activeFilterCount > 0 ? `Filter jobs, ${activeFilterCount} active` : "Filter jobs";

  return (
    <Dialog
      onOpenChange={(open) => {
        if (open) {
          onIntent();
        }
      }}
    >
      <DialogTrigger
        render={
          <Button
            type="button"
            variant="action"
            size="icon-sm"
            className="relative text-muted-foreground"
            aria-label={label}
            title={label}
            onFocus={onIntent}
            onPointerEnter={onIntent}
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
          <DialogTitle>Filter jobs</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <FieldGroup>
          {filterKeys.map((filterKey) =>
            freeTextFilterKeys.has(filterKey) ? (
              <FilterTextInput
                key={filterKey}
                label={filterLabels[filterKey].label}
                value={values[filterKey]}
                onValueChange={(value) => onFilterChange(filterKey, value)}
              />
            ) : (
              <FilterSelect
                key={filterKey}
                label={filterLabels[filterKey].label}
                allLabel={filterLabels[filterKey].allLabel}
                options={filterOptions(filterKey, options)}
                value={values[filterKey]}
                onValueChange={(value) => onFilterChange(filterKey, value)}
              />
            ),
          )}
        </FieldGroup>
        <DialogFooter className="sm:justify-between">
          <Button
            type="button"
            variant="ghost"
            disabled={activeFilterCount === 0}
            onClick={onClearFilters}
          >
            Clear filters
          </Button>
          <DialogClose render={<Button type="button" />}>Done</DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function filterOptions(
  filterKey: JobFilterKey,
  options: Partial<Record<JobFilterKey, readonly JobFilterOption[]>>,
): readonly JobFilterOption[] {
  if (filterKey === "state") {
    return [
      { label: "Ready", value: "ready" },
      { label: "Reserved", value: "reserved" },
      { label: "Delayed", value: "delayed" },
      { label: "Released", value: "released" },
    ];
  }

  return options[filterKey] ?? [];
}

function FilterSelect({
  label,
  allLabel,
  options,
  value,
  onValueChange,
}: {
  label: string;
  allLabel: string;
  options: readonly JobFilterOption[];
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

function FilterTextInput({
  label,
  value,
  onValueChange,
}: {
  label: string;
  value: string | null;
  onValueChange: (value: string | null) => void;
}) {
  const inputId = useId();

  return (
    <Field>
      <FieldLabel htmlFor={inputId}>{label}</FieldLabel>
      <Input
        id={inputId}
        type="text"
        value={value ?? ""}
        placeholder={`Enter an exact ${label.toLowerCase()}`}
        onChange={(event) => {
          const nextValue = event.target.value;

          onValueChange(nextValue === "" ? null : nextValue);
        }}
      />
    </Field>
  );
}
