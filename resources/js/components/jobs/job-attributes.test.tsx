import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { JobAttributesPanel } from "@/components/jobs/job-attributes";
import type { JobAttributes } from "@/types/jobs";

const baseAttributes: JobAttributes = {
  tries: null,
  backoff: null,
  timeout: null,
  failOnTimeout: false,
  maxExceptions: null,
  uniqueFor: null,
  debounceFor: null,
  debounceMaxWait: null,
  queue: null,
  connection: null,
  delay: null,
  withoutRelations: false,
  deleteWhenMissingModels: false,
  routedQueue: null,
  routedConnection: null,
};

describe("JobAttributesPanel", () => {
  it("renders nothing when there are no attributes to show", () => {
    const { container } = render(<JobAttributesPanel attributes={baseAttributes} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when no attributes prop is passed", () => {
    const { container } = render(<JobAttributesPanel />);

    expect(container).toBeEmptyDOMElement();
  });

  it("shows configured attribute values and the routed destination", () => {
    render(
      <JobAttributesPanel
        attributes={{
          ...baseAttributes,
          tries: 5,
          backoff: [10, 30, 60],
          timeout: 120,
          maxExceptions: 3,
          uniqueFor: 300,
          queue: "reports",
          connection: "redis",
          routedQueue: "reports",
          routedConnection: "redis",
        }}
      />,
    );

    expect(screen.getByText("Attributes")).toBeVisible();
    expect(screen.getByText("Max Tries")).toBeVisible();
    expect(screen.getByText("5")).toBeVisible();
    expect(screen.getByText("Backoff")).toBeVisible();
    expect(screen.getByText("10, 30, 60")).toBeVisible();
    expect(screen.getByText("Max Exceptions")).toBeVisible();
    expect(screen.getByText("Routed Queue")).toBeVisible();
    expect(screen.getByText("Routed Connection")).toBeVisible();
  });

  it("shows flag badges without a details list when only flags are set", () => {
    render(
      <JobAttributesPanel
        attributes={{
          ...baseAttributes,
          failOnTimeout: true,
          withoutRelations: true,
          deleteWhenMissingModels: true,
        }}
      />,
    );

    expect(screen.getByText("Fails on timeout")).toBeVisible();
    expect(screen.getByText("Without relations")).toBeVisible();
    expect(screen.getByText("Deletes when models missing")).toBeVisible();
  });
});
