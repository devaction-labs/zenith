import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { emptyJobFilterValues, JobFilters, jobFilterKeys } from "@/components/jobs/job-filters";

describe("JobFilters tag facet", () => {
  it("renders the tag filter as a free-text input on every job list scope", () => {
    expect(jobFilterKeys("pending")).toContain("tag");
    expect(jobFilterKeys("completed")).toContain("tag");
    expect(jobFilterKeys("silenced")).toContain("tag");
    expect(jobFilterKeys("failed")).toContain("tag");
  });

  it("reports the exact tag typed into the filter dialog", () => {
    const onFilterChange = vi.fn();

    render(
      <JobFilters
        filterKeys={["job", "queue", "connection", "tag"]}
        options={{}}
        values={emptyJobFilterValues}
        onIntent={() => {}}
        onFilterChange={onFilterChange}
        onClearFilters={() => {}}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Filter jobs" }));
    fireEvent.change(screen.getByLabelText("Tag"), { target: { value: "tenant:42" } });

    expect(onFilterChange).toHaveBeenCalledWith("tag", "tenant:42");
  });

  it("clears an active tag filter back to null", () => {
    const onFilterChange = vi.fn();

    render(
      <JobFilters
        filterKeys={["job", "queue", "connection", "tag"]}
        options={{}}
        values={{ ...emptyJobFilterValues, tag: "tenant:42" }}
        onIntent={() => {}}
        onFilterChange={onFilterChange}
        onClearFilters={() => {}}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Filter jobs, 1 active" }));
    fireEvent.change(screen.getByLabelText("Tag"), { target: { value: "" } });

    expect(onFilterChange).toHaveBeenCalledWith("tag", null);
  });
});
