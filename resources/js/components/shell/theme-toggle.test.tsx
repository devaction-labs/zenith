import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { ThemeToggle } from "@/components/shell/theme-toggle";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ColorSchemeProvider } from "@/hooks/use-color-scheme";

describe("ThemeToggle", () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove("dark");
    Object.defineProperty(window, "matchMedia", {
      configurable: true,
      value: vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }),
    });
  });

  it("describes and applies the next color scheme", () => {
    render(
      <ColorSchemeProvider>
        <TooltipProvider>
          <ThemeToggle />
        </TooltipProvider>
      </ColorSchemeProvider>,
    );

    const toggle = screen.getByRole("button", { name: "Color scheme: system. Switch to dark." });
    fireEvent.click(toggle);

    expect(
      screen.getByRole("button", { name: "Color scheme: dark. Switch to light." }),
    ).toBeVisible();
    expect(document.documentElement).toHaveClass("dark");
  });
});
