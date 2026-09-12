import { fireEvent, render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { useGlobalShortcuts } from "@/hooks/use-keyboard-shortcuts";

const { visit } = vi.hoisted(() => ({
  visit: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: { visit },
}));

function Harness({ onOpenHelp = vi.fn() }: { onOpenHelp?: () => void }) {
  useGlobalShortcuts({ baseUrl: "/horizon", onOpenHelp });

  return (
    <div>
      <input aria-label="Plain input" />
      <textarea aria-label="Plain textarea" />
      <div aria-label="Editable region" contentEditable />
      <input role="searchbox" aria-label="Page search" />
    </div>
  );
}

function pressKey(target: Document | Element, key: string, init: KeyboardEventInit = {}) {
  fireEvent.keyDown(target, { key, ...init });
}

describe("useGlobalShortcuts", () => {
  beforeEach(() => {
    visit.mockReset();
  });

  it("navigates to the dashboard on g then d", () => {
    render(<Harness />);

    pressKey(document, "g");
    pressKey(document, "d");

    expect(visit).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledWith("/horizon");
  });

  it("navigates to pending jobs on g then j", () => {
    render(<Harness />);

    pressKey(document, "g");
    pressKey(document, "j");

    expect(visit).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledWith("/horizon/jobs/pending");
  });

  it("navigates to failed jobs on g then f", () => {
    render(<Harness />);

    pressKey(document, "g");
    pressKey(document, "f");

    expect(visit).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledWith("/horizon/failed");
  });

  it("does not navigate on an unmatched key after g", () => {
    render(<Harness />);

    pressKey(document, "g");
    pressKey(document, "x");

    expect(visit).not.toHaveBeenCalled();
  });

  it("does not treat d alone as a shortcut without the g prefix", () => {
    render(<Harness />);

    pressKey(document, "d");

    expect(visit).not.toHaveBeenCalled();
  });

  it("focuses the page's search box on /", () => {
    const { getByLabelText } = render(<Harness />);

    pressKey(document, "/");

    expect(getByLabelText("Page search")).toHaveFocus();
  });

  it("does nothing on / when the page has no search box", () => {
    render(
      <div>
        <input aria-label="Plain input" />
      </div>,
    );

    expect(() => pressKey(document, "/")).not.toThrow();
  });

  it("opens the help dialog on ?", () => {
    const onOpenHelp = vi.fn();
    render(<Harness onOpenHelp={onOpenHelp} />);

    pressKey(document, "?");

    expect(onOpenHelp).toHaveBeenCalledOnce();
  });

  it("never fires shortcuts while focus is inside an input", () => {
    const onOpenHelp = vi.fn();
    const { getByLabelText } = render(<Harness onOpenHelp={onOpenHelp} />);
    const input = getByLabelText("Plain input");

    input.focus();
    pressKey(input, "g");
    pressKey(input, "d");
    pressKey(input, "/");
    pressKey(input, "?");

    expect(visit).not.toHaveBeenCalled();
    expect(onOpenHelp).not.toHaveBeenCalled();
  });

  it("never fires shortcuts while focus is inside a textarea", () => {
    const onOpenHelp = vi.fn();
    const { getByLabelText } = render(<Harness onOpenHelp={onOpenHelp} />);
    const textarea = getByLabelText("Plain textarea");

    textarea.focus();
    pressKey(textarea, "g");
    pressKey(textarea, "d");
    pressKey(textarea, "?");

    expect(visit).not.toHaveBeenCalled();
    expect(onOpenHelp).not.toHaveBeenCalled();
  });

  it("never fires shortcuts while focus is inside a contenteditable element", () => {
    const onOpenHelp = vi.fn();
    const { getByLabelText } = render(<Harness onOpenHelp={onOpenHelp} />);
    const editable = getByLabelText("Editable region");

    editable.focus();
    pressKey(editable, "g");
    pressKey(editable, "d");
    pressKey(editable, "?");

    expect(visit).not.toHaveBeenCalled();
    expect(onOpenHelp).not.toHaveBeenCalled();
  });

  it("forgets a pending g prefix after it has been idle too long", () => {
    vi.useFakeTimers();

    render(<Harness />);

    pressKey(document, "g");
    vi.advanceTimersByTime(2000);
    pressKey(document, "d");

    expect(visit).not.toHaveBeenCalled();

    vi.useRealTimers();
  });

  it("ignores shortcut keys pressed with a modifier held", () => {
    const onOpenHelp = vi.fn();
    render(<Harness onOpenHelp={onOpenHelp} />);

    pressKey(document, "?", { metaKey: true });
    pressKey(document, "/", { ctrlKey: true });

    expect(onOpenHelp).not.toHaveBeenCalled();
  });
});
