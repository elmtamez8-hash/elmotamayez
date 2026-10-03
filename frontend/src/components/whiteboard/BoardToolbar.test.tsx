import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BoardToolbar, type BoardToolbarProps } from "./BoardToolbar";

function props(overrides: Partial<BoardToolbarProps> = {}): BoardToolbarProps {
  return {
    title: "مراجعة الكيمياء",
    background: "white",
    canEdit: true,
    pageIndex: 1,
    pageCount: 3,
    presenting: false,
    onPrevious: vi.fn(),
    onNext: vi.fn(),
    onRename: vi.fn().mockResolvedValue(undefined),
    onBackground: vi.fn(),
    onExport: vi.fn().mockResolvedValue(undefined),
    onTogglePresenting: vi.fn(),
    ...overrides,
  };
}

/** The panel is folded until the teacher opens it. */
const unfold = () => fireEvent.click(screen.getByRole("button", { name: "افتح لوحة التحكم" }));

beforeEach(() => localStorage.clear());

describe("BoardToolbar", () => {
  it("starts folded with the pages and «عرض» in reach, opens on a press, and remembers it", () => {
    const { unmount } = render(<BoardToolbar {...props()} />);

    expect(screen.getByText("٢ من ٣")).toBeTruthy();
    expect(screen.getByRole("button", { name: "عرض" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "PNG" })).toBeNull();

    unfold();
    expect(screen.getByRole("button", { name: "PNG" })).toBeTruthy();
    unmount();

    render(<BoardToolbar {...props()} />);
    expect(screen.getByRole("button", { name: "اطوِ لوحة التحكم" }).getAttribute("aria-expanded")).toBe("true");
  }, 15000);

  it("moves between pages and shows where the teacher is", () => {
    const p = props();
    render(<BoardToolbar {...p} />);

    expect(screen.getByText("٢ من ٣")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "الصفحة السابقة" }));
    fireEvent.click(screen.getByRole("button", { name: "الصفحة التالية" }));

    expect(p.onPrevious).toHaveBeenCalledTimes(1);
    expect(p.onNext).toHaveBeenCalledTimes(1);
  });

  it("disables «السابقة» on the first page and «التالية» on the last", () => {
    const { rerender } = render(<BoardToolbar {...props({ pageIndex: 0 })} />);
    expect((screen.getByRole("button", { name: "الصفحة السابقة" }) as HTMLButtonElement).disabled).toBe(true);

    rerender(<BoardToolbar {...props({ pageIndex: 2 })} />);
    expect((screen.getByRole("button", { name: "الصفحة التالية" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("exports the page as PNG and as SVG", async () => {
    const p = props();
    render(<BoardToolbar {...p} />);
    unfold();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "PNG" }));
    });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "SVG" }));
    });

    expect(p.onExport).toHaveBeenNthCalledWith(1, "png");
    expect(p.onExport).toHaveBeenNthCalledWith(2, "svg");
  });

  it("says so when an export fails, instead of failing silently", async () => {
    render(<BoardToolbar {...props({ onExport: vi.fn().mockRejectedValue(new Error("boom")) })} />);
    unfold();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "SVG" }));
    });

    expect(screen.getByRole("alert").textContent).toBe("تعذّر التصدير. أعد المحاولة.");
  });

  it("renames the board and switches its background", async () => {
    const p = props();
    render(<BoardToolbar {...p} />);
    unfold();

    fireEvent.click(screen.getByRole("button", { name: "إعادة التسمية" }));
    fireEvent.change(screen.getByLabelText("عنوان السبّورة"), { target: { value: "مراجعة الفيزياء" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حفظ" }));
    });
    fireEvent.change(screen.getByLabelText("الخلفية"), { target: { value: "blackboard" } });

    expect(p.onRename).toHaveBeenCalledWith("مراجعة الفيزياء");
    expect(p.onBackground).toHaveBeenCalledWith("blackboard");
  });

  it("keeps a read-only board's name and background out of reach", () => {
    render(<BoardToolbar {...props({ canEdit: false })} />);
    unfold();

    expect((screen.getByRole("button", { name: "إعادة التسمية" }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByLabelText("الخلفية") as HTMLSelectElement).disabled).toBe(true);
  });

  it("toggles «عرض»", () => {
    const p = props();
    const { rerender } = render(<BoardToolbar {...p} />);

    fireEvent.click(screen.getByRole("button", { name: "عرض" }));
    expect(p.onTogglePresenting).toHaveBeenCalledTimes(1);

    rerender(<BoardToolbar {...props({ presenting: true })} />);
    expect(screen.getByRole("button", { name: "إنهاء العرض" })).toBeTruthy();
  });
});

describe("the screens of a page", () => {
  it("moves up and down a screen, and offers empty space on the last one", () => {
    const onScreen = vi.fn();
    const { rerender } = render(<BoardToolbar {...props({ screen: { index: 0, count: 1 }, onScreen })} />);
    unfold();

    expect((screen.getByRole("button", { name: "أعلى" }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByRole("button", { name: "مكان فارغ" }));
    expect(onScreen).toHaveBeenCalledWith(1);

    rerender(<BoardToolbar {...props({ screen: { index: 0, count: 3 }, onScreen })} />);
    expect(screen.getByText("شاشة ١ من ٣")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "أسفل" }));
    expect(onScreen).toHaveBeenLastCalledWith(1);
  });
});
