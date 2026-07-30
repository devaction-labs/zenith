import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { TabbedResultsCard } from "@/components/tabbed-results-card";

vi.mock("@inertiajs/react", async () => {
  const { inertiaTestMocks } = await import("@/test/inertia-mock");

  return inertiaTestMocks();
});

describe("TabbedResultsCard", () => {
  it("supports a server-filter toolbar without presenting an unsupported search", () => {
    render(
      <TabbedResultsCard
        title="Jobs"
        activeTab="pending"
        tabs={[{ value: "pending", label: "Pending", href: "/horizon/jobs/pending" }]}
        tabListLabel="Job status"
        contentHeading="Pending jobs"
        filters={<button type="button">Filter jobs</button>}
      >
        Results
      </TabbedResultsCard>,
    );

    expect(screen.queryByRole("searchbox")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Filter jobs" })).toBeVisible();
    expect(screen.getByText("Results")).toBeVisible();
  });
});
