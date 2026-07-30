import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vite-plus/test";

import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";

describe("Tabs", () => {
  it("lets line lists follow their triggers and anchors the active indicator to the bottom", () => {
    render(
      <Tabs value="jobs">
        <TabsList variant="line" aria-label="Metric type">
          <TabsTrigger value="jobs">Jobs</TabsTrigger>
          <TabsTrigger value="queues">Queues</TabsTrigger>
        </TabsList>
      </Tabs>,
    );

    expect(screen.getByRole("tablist", { name: "Metric type" })).toHaveClass(
      "group-data-horizontal/tabs:h-auto",
    );
    expect(document.querySelector('[data-slot="tabs-indicator"]')).toHaveClass(
      "group-data-horizontal/tabs:bottom-0",
    );
  });
});
