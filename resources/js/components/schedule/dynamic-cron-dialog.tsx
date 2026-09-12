import { useForm } from "@inertiajs/react";
import { useEffect } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  store as createDynamicCron,
  update as updateDynamicCron,
} from "@/generated/routes/zenith/schedule/dynamic-crons";
import { resolveHorizonRoute } from "@/lib/horizon-route";
import type { ScheduleEvent } from "@/types/schedule";

function emptyFormValues() {
  return { name: "", expression: "", job_class: "", payload: "", timezone: "" };
}

function formValuesFor(cron: ScheduleEvent | null) {
  if (!cron) {
    return emptyFormValues();
  }

  return {
    name: cron.description,
    expression: cron.expression,
    job_class: cron.command ?? "",
    payload: cron.payload ? JSON.stringify(cron.payload, null, 2) : "",
    timezone: cron.timezone ?? "",
  };
}

export function DynamicCronDialog({
  open,
  onOpenChange,
  horizonBaseUrl,
  cron,
  allowedClasses,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  horizonBaseUrl: string;
  cron: ScheduleEvent | null;
  allowedClasses: string[];
}) {
  const form = useForm(emptyFormValues());
  const isEditing = cron?.dynamicCronId != null;

  useEffect(() => {
    if (!open) {
      return;
    }

    form.setData(formValuesFor(cron));
    form.clearErrors();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, cron]);

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const options = {
      preserveScroll: true,
      onSuccess: () => onOpenChange(false),
    };

    if (isEditing && cron?.dynamicCronId != null) {
      form.put(
        resolveHorizonRoute(updateDynamicCron(cron.dynamicCronId), horizonBaseUrl).url,
        options,
      );

      return;
    }

    form.post(resolveHorizonRoute(createDynamicCron(), horizonBaseUrl).url, options);
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);

        if (!next) {
          form.clearErrors();
        }
      }}
    >
      <DialogContent>
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>{isEditing ? "Edit dynamic cron" : "Create dynamic cron"}</DialogTitle>
            <DialogDescription>
              Dynamic crons dispatch a queued job on a cron schedule you manage from the dashboard.
              Job classes are limited to the configured allowlist.
            </DialogDescription>
          </DialogHeader>
          <FieldGroup className="py-5">
            <Field data-invalid={form.errors.name ? true : undefined}>
              <FieldLabel htmlFor="dynamic-cron-name">Name</FieldLabel>
              <Input
                id="dynamic-cron-name"
                value={form.data.name}
                aria-invalid={form.errors.name ? true : undefined}
                autoFocus
                onChange={(event) => form.setData("name", event.target.value)}
              />
              <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={form.errors.expression ? true : undefined}>
              <FieldLabel htmlFor="dynamic-cron-expression">Cron expression</FieldLabel>
              <Input
                id="dynamic-cron-expression"
                className="font-mono"
                placeholder="0 3 * * *"
                value={form.data.expression}
                aria-invalid={form.errors.expression ? true : undefined}
                onChange={(event) => form.setData("expression", event.target.value)}
              />
              <FieldError>{form.errors.expression}</FieldError>
            </Field>
            <Field data-invalid={form.errors.job_class ? true : undefined}>
              <FieldLabel htmlFor="dynamic-cron-job-class">Job class</FieldLabel>
              {allowedClasses.length > 0 ? (
                <Select
                  items={allowedClasses.map((jobClass) => ({ label: jobClass, value: jobClass }))}
                  value={form.data.job_class || null}
                  onValueChange={(value) => form.setData("job_class", value ?? "")}
                >
                  <SelectTrigger id="dynamic-cron-job-class" className="w-full">
                    <SelectValue placeholder="Select a job class" />
                  </SelectTrigger>
                  <SelectContent alignItemWithTrigger={false} listLabel="Job class options">
                    <SelectGroup>
                      {allowedClasses.map((jobClass) => (
                        <SelectItem key={jobClass} value={jobClass}>
                          {jobClass}
                        </SelectItem>
                      ))}
                    </SelectGroup>
                  </SelectContent>
                </Select>
              ) : (
                <p className="text-sm text-muted-foreground">
                  No job classes are configured. Add classes to zenith.dynamic_cron_allowed_classes
                  to enable dynamic crons.
                </p>
              )}
              <FieldError>{form.errors.job_class}</FieldError>
            </Field>
            <Field data-invalid={form.errors.timezone ? true : undefined}>
              <FieldLabel htmlFor="dynamic-cron-timezone">Timezone</FieldLabel>
              <Input
                id="dynamic-cron-timezone"
                placeholder="UTC"
                value={form.data.timezone}
                aria-invalid={form.errors.timezone ? true : undefined}
                onChange={(event) => form.setData("timezone", event.target.value)}
              />
              <FieldError>{form.errors.timezone}</FieldError>
            </Field>
            <Field data-invalid={form.errors.payload ? true : undefined}>
              <FieldLabel htmlFor="dynamic-cron-payload">Payload (JSON)</FieldLabel>
              <Textarea
                id="dynamic-cron-payload"
                className="font-mono"
                rows={4}
                placeholder="{}"
                value={form.data.payload}
                aria-invalid={form.errors.payload ? true : undefined}
                onChange={(event) => form.setData("payload", event.target.value)}
              />
              <FieldError>{form.errors.payload}</FieldError>
            </Field>
          </FieldGroup>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" />}>Cancel</DialogClose>
            <Button type="submit" disabled={form.processing || allowedClasses.length === 0}>
              {form.processing
                ? isEditing
                  ? "Saving…"
                  : "Creating…"
                : isEditing
                  ? "Save"
                  : "Create"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
