import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { PagesSidebar, type PagesSidebarProps } from "./PagesSidebar";

/*
| Spec 039 · US3 (T071). jsdom has no IntersectionObserver; this one is driven by
| the test, so «on screen» is whatever the test says it is.
*/
const observed = new Map<Element, (entries: { isIntersecting: boolean }[]) => void>();

class FakeObserver {
  constructor(private readonly callback: (entries: { isIntersecting: boolean }[]) => void) {}
  observe(node: Element) {
    observed.set(node, this.callback);
  }
  disconnect() {}
}

function show(uuid: string, visible = true) {
  const node = document.querySelector(`[data-thumb="${uuid}"]`);
  if (!node) throw new Error(`no thumb ${uuid}`);
  act(() => observed.get(node)?.([{ isIntersecting: visible }]));
}

function props(over: Partial<PagesSidebarProps> = {}): PagesSidebarProps {
  return {
    pages: [
      { uuid: "a", version: 1 },
      { uuid: "b", version: 1 },
      { uuid: "c", version: 1 },
    ],
    current: 0,
    canEdit: true,
    busy: false,
    thumbnail: vi.fn(async (uuid: string) => `data:image/png;base64,${uuid}`),
    onSelect: vi.fn(),
    onAdd: vi.fn(),
    onDuplicate: vi.fn(),
    onDelete: vi.fn(),
    onReorder: vi.fn(),
    ...over,
  };
}

beforeEach(() => {
  observed.clear();
  vi.stubGlobal("IntersectionObserver", FakeObserver);
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe("PagesSidebar", () => {
  it("draws only the pages on screen, and a page once per version (US3-1)", async () => {
    const p = props();
    const view = render(<PagesSidebar {...p} />);

    await act(() => vi.advanceTimersByTimeAsync(100));
    expect(p.thumbnail).not.toHaveBeenCalled();

    show("b");
    await act(() => vi.advanceTimersByTimeAsync(100));
    expect(p.thumbnail).toHaveBeenCalledTimes(1);
    expect(p.thumbnail).toHaveBeenCalledWith("b");

    // Scrolled away and back: same version, no second drawing.
    show("b", false);
    show("b");
    await act(() => vi.advanceTimersByTimeAsync(100));
    expect(p.thumbnail).toHaveBeenCalledTimes(1);

    // The page changed: drawn again — once the drawing has paused, not per stroke.
    view.rerender(<PagesSidebar {...p} pages={[{ uuid: "a", version: 1 }, { uuid: "b", version: 2 }, { uuid: "c", version: 1 }]} />);
    await act(() => vi.advanceTimersByTimeAsync(1000));
    view.rerender(<PagesSidebar {...p} pages={[{ uuid: "a", version: 1 }, { uuid: "b", version: 3 }, { uuid: "c", version: 1 }]} />);
    await act(() => vi.advanceTimersByTimeAsync(2000));
    expect(p.thumbnail).toHaveBeenCalledTimes(1);
    await act(() => vi.advanceTimersByTimeAsync(1200));
    expect(p.thumbnail).toHaveBeenCalledTimes(2);
  });

  it("reorders with the move buttons, and adds after the current page", () => {
    const p = props({ current: 1 });
    render(<PagesSidebar {...p} />);

    fireEvent.click(screen.getAllByRole("button", { name: "انقل الصفحة لأسفل" })[0]);
    expect(p.onReorder).toHaveBeenCalledWith(["b", "a", "c"]);

    fireEvent.click(screen.getByRole("button", { name: "صفحة جديدة" }));
    expect(p.onAdd).toHaveBeenCalledWith("b");
  });

  it("offers no change to a reader, and no delete for the last page", () => {
    const { rerender } = render(<PagesSidebar {...props({ canEdit: false })} />);
    expect(screen.queryByRole("button", { name: "صفحة جديدة" })).toBeNull();

    rerender(<PagesSidebar {...props({ pages: [{ uuid: "a", version: 1 }] })} />);
    expect(screen.queryByRole("button", { name: "حذف" })).toBeNull();
  });
});

describe("folding the pages list", () => {
  it("folds from its own header, and the tab says where the teacher is", async () => {
    const { PagesTab } = await import("./PagesSidebar");
    const onCollapse = vi.fn();
    const onOpen = vi.fn();
    const { unmount } = render(
      <PagesSidebar pages={[{ uuid: "a", version: 1 }]} current={0} canEdit busy={false} thumbnail={async () => ""} onSelect={vi.fn()} onAdd={vi.fn()} onDuplicate={vi.fn()} onDelete={vi.fn()} onReorder={vi.fn()} onCollapse={onCollapse} />,
    );
    fireEvent.click(screen.getByRole("button", { name: "اطوِ قائمة الصفحات" }));
    expect(onCollapse).toHaveBeenCalledTimes(1);
    unmount();

    render(<PagesTab current={1} count={5} onOpen={onOpen} />);
    // «٢» over «من ٥», as the bar says it.
    expect(screen.getByText("٢")).toBeTruthy();
    expect(screen.getByText("من ٥")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "افتح قائمة الصفحات" }));
    expect(onOpen).toHaveBeenCalledTimes(1);
  });
});
