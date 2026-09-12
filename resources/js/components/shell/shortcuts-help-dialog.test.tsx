import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vite-plus/test";

import { ShortcutsHelpDialog } from "@/components/shell/shortcuts-help-dialog";

describe("ShortcutsHelpDialog", () => {
  it("lists every documented shortcut while open", () => {
    render(<ShortcutsHelpDialog open onOpenChange={vi.fn()} />);

    expect(screen.getByRole("dialog", { name: "Keyboard shortcuts" })).toBeVisible();
    expect(screen.getByText("Go to the dashboard")).toBeVisible();
    expect(screen.getByText("Go to pending jobs")).toBeVisible();
    expect(screen.getByText("Go to failed jobs")).toBeVisible();
    expect(screen.getByText("Focus this page's search box")).toBeVisible();
    expect(screen.getByText("Show this help dialog")).toBeVisible();
  });

  it("renders nothing while closed", () => {
    render(<ShortcutsHelpDialog open={false} onOpenChange={vi.fn()} />);

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });
});
