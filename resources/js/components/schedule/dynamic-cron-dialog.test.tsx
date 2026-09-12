import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { DynamicCronDialog } from "@/components/schedule/dynamic-cron-dialog";
import type { ScheduleEvent } from "@/types/schedule";

const formCalls = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
const formErrors = vi.hoisted(() => ({ current: {} as Record<string, string> }));

vi.mock("@inertiajs/react", () => ({
  useForm: (initial: Record<string, string>) => {
    const [data, setDataState] = useState(initial);

    return {
      data,
      errors: formErrors.current,
      processing: false,
      setData: (keyOrData: string | Record<string, string>, value?: string) => {
        if (typeof keyOrData === "string") {
          setDataState((previous) => ({ ...previous, [keyOrData]: value ?? "" }));

          return;
        }

        setDataState(keyOrData);
      },
      clearErrors: () => {},
      post: (url: string, options?: { onSuccess?: () => void }) => {
        formCalls.post(url, data);
        options?.onSuccess?.();
      },
      put: (url: string, options?: { onSuccess?: () => void }) => {
        formCalls.put(url, data);
        options?.onSuccess?.();
      },
    };
  },
}));

function dynamicCronEvent(overrides: Partial<ScheduleEvent> = {}): ScheduleEvent {
  return {
    id: "dynamic-1",
    expression: "0 3 * * *",
    description: "nightly-report",
    command: "App\\Jobs\\Safe",
    timezone: "America/Sao_Paulo",
    nextRunAt: 1_700_000_000,
    withoutOverlapping: false,
    onOneServer: true,
    evenInMaintenanceMode: false,
    runInBackground: false,
    overlapping: false,
    runtimeEditable: true,
    paused: false,
    history: [],
    dynamicCronId: 1,
    payload: { seed: [1, 2] },
    ...overrides,
  };
}

describe("DynamicCronDialog", () => {
  beforeEach(() => {
    formCalls.post.mockReset();
    formCalls.put.mockReset();
    formErrors.current = {};
  });

  it("starts with empty fields when creating a new cron", () => {
    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={null}
        allowedClasses={["App\\Jobs\\Safe"]}
      />,
    );

    expect(screen.getByText("Create dynamic cron")).toBeVisible();
    expect(screen.getByLabelText("Name")).toHaveValue("");
    expect(screen.getByRole("button", { name: "Create" })).toBeVisible();
  });

  it("prefills every field when editing an existing cron", () => {
    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={dynamicCronEvent()}
        allowedClasses={["App\\Jobs\\Safe"]}
      />,
    );

    expect(screen.getByText("Edit dynamic cron")).toBeVisible();
    expect(screen.getByLabelText("Name")).toHaveValue("nightly-report");
    expect(screen.getByLabelText("Cron expression")).toHaveValue("0 3 * * *");
    expect(screen.getByLabelText("Timezone")).toHaveValue("America/Sao_Paulo");
    expect(screen.getByLabelText("Payload (JSON)")).toHaveValue(
      JSON.stringify({ seed: [1, 2] }, null, 2),
    );
  });

  it("posts to the create route when submitting a new cron", () => {
    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={null}
        allowedClasses={["App\\Jobs\\Safe"]}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Create" }));

    expect(formCalls.post).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons",
      expect.anything(),
    );
  });

  it("puts to the update route when submitting an edited cron", () => {
    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={dynamicCronEvent({ dynamicCronId: 42 })}
        allowedClasses={["App\\Jobs\\Safe"]}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    expect(formCalls.put).toHaveBeenCalledWith(
      "/horizon/schedule/dynamic-crons/42",
      expect.anything(),
    );
  });

  it("disables submission and explains when no job classes are configured", () => {
    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={null}
        allowedClasses={[]}
      />,
    );

    expect(screen.getByText(/no job classes are configured/i)).toBeVisible();
    expect(screen.getByRole("button", { name: "Create" })).toBeDisabled();
  });

  it("shows a server validation error next to its field", () => {
    formErrors.current = { expression: "The expression must be a valid cron expression." };

    render(
      <DynamicCronDialog
        open
        onOpenChange={vi.fn()}
        horizonBaseUrl="/horizon"
        cron={null}
        allowedClasses={["App\\Jobs\\Safe"]}
      />,
    );

    expect(screen.getByText("The expression must be a valid cron expression.")).toBeVisible();
  });
});
