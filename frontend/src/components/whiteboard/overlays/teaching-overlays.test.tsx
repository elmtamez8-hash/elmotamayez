import { WB } from "@/lib/whiteboard/strings";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { BoardToolbar, type BoardToolbarProps } from "../BoardToolbar";
import { TeachingBar, type TeachingBarProps } from "../TeachingBar";
import { CURTAIN_START, CURTAIN_STEP, Curtain } from "./Curtain";
import { Magnifier } from "./Magnifier";
import { Wheel } from "./Wheel";

afterEach(() => vi.useRealTimers());

describe("Curtain", () => {
  it("covers part of the board and reveals it bit by bit", () => {
    const onClose = vi.fn();
    const { container } = render(<Curtain onClose={onClose} />);
    const curtain = container.querySelector("[data-effect='curtain']");
    expect(curtain?.getAttribute("data-cover")).toBe(CURTAIN_START.toFixed(2));

    fireEvent.click(screen.getByRole("button", { name: "اكشف قليلاً" }));
    expect(curtain?.getAttribute("data-cover")).toBe((CURTAIN_START - CURTAIN_STEP).toFixed(2));

    fireEvent.click(screen.getByRole("button", { name: "إزالة الستارة" }));
    expect(onClose).toHaveBeenCalled();
  });
});

describe("Wheel", () => {
  beforeEach(() => localStorage.clear());

  const spinOnce = () => {
    fireEvent.click(screen.getByRole("button", { name: "أدر العجلة" }));
    act(() => vi.advanceTimersByTime(5000));
    return screen.getByRole("status").textContent ?? "";
  };
  const order = () => [...document.querySelectorAll("[data-wheel-order] li")].map((li) => li.textContent?.replace(/^[٠-٩]+\./, ""));

  it("records the order of the picks, never repeats in a round, and never edits the names", () => {
    vi.useFakeTimers();
    render(<Wheel board="b1" sound={false} onClose={vi.fn()} />);
    const names = screen.getByRole("textbox") as HTMLTextAreaElement;
    fireEvent.change(names, { target: { value: "أحمد\nمريم\nيوسف" } });

    const picked = [spinOnce(), spinOnce()];
    // The last one left needs no spin.
    fireEvent.click(screen.getByRole("button", { name: /^الأخير:/ }));
    picked.push(screen.getByRole("status").textContent ?? "");

    expect([...picked].sort()).toEqual(["أحمد", "مريم", "يوسف"].sort()); // each exactly once
    expect(order()).toEqual(picked); // in the order they were picked
    expect(names.value).toBe("أحمد\nمريم\nيوسف"); // the names themselves untouched
  });

  it("keeps the names and the order after the wheel is closed and opened again", () => {
    vi.useFakeTimers();
    const first = render(<Wheel board="b2" sound={false} onClose={vi.fn()} />);
    fireEvent.change(screen.getByRole("textbox"), { target: { value: "سارة\nعمر\nنور" } });
    const picked = spinOnce();
    first.unmount();

    render(<Wheel board="b2" sound={false} onClose={vi.fn()} />);
    expect((screen.getByRole("textbox") as HTMLTextAreaElement).value).toBe("سارة\nعمر\nنور");
    expect(order()).toEqual([picked]);

    fireEvent.click(screen.getByRole("button", { name: "جولة جديدة" }));
    expect(order()).toEqual([]);
  });

  it("will not spin with no names", () => {
    render(<Wheel board="b3" sound={false} onClose={vi.fn()} />);
    expect((screen.getByRole("button", { name: "أدر العجلة" }) as HTMLButtonElement).disabled).toBe(true);
  });
});

describe("Magnifier", () => {
  it("appears, and Esc puts it away", () => {
    const onClose = vi.fn();
    const { container } = render(<Magnifier background="#ffffff" onClose={onClose} />);
    expect(container.querySelector("[data-effect='magnifier']")).not.toBeNull();
    fireEvent.keyDown(window, { key: "Escape" });
    expect(onClose).toHaveBeenCalled();
  });
});

describe("TeachingBar", () => {
  const props = (over: Partial<TeachingBarProps> = {}): TeachingBarProps => ({
    canEdit: true,
    template: null,
    open: null,
    onTemplate: vi.fn(),
    onPen: vi.fn(),
    onTool: vi.fn(),
    instrument: null,
    onInstrument: vi.fn(),
    onTable: vi.fn(),
    onMath: vi.fn(),
    onCalculator: vi.fn(),
    onGraph: vi.fn(),
    ...over,
  });

  it("opens the table editor from «إدراج جدول» (story 6)", () => {
    const p = props();
    render(<TeachingBar {...p} />);
    fireEvent.click(screen.getByRole("button", { name: WB.table.insert }));
    expect(p.onTable).toHaveBeenCalled();
  });

  it("sets a template, a pen, and opens a passing tool", () => {
    const p = props();
    render(<TeachingBar {...p} />);
    fireEvent.click(screen.getByRole("button", { name: "كرّاسة عربية" }));
    fireEvent.click(screen.getByRole("button", { name: "فرشاة عريضة" }));
    fireEvent.click(screen.getByRole("button", { name: "عجلة الاختيار" }));

    expect(p.onTemplate).toHaveBeenCalledWith("arabic-lines");
    expect(p.onPen).toHaveBeenCalledWith("brush");
    expect(p.onTool).toHaveBeenCalledWith("wheel");
  });

  it("keeps templates and pens from a reader, but not the passing tools", () => {
    render(<TeachingBar {...props({ canEdit: false })} />);
    expect(screen.queryByRole("button", { name: "مربّعات" })).toBeNull();
    expect(screen.getByRole("button", { name: "عدسة" })).toBeTruthy();
  });
});

describe("the toolbar's menus", () => {
  it("opens one menu at a time, and closes it on a second press", () => {
    const props: BoardToolbarProps = {
      title: "س",
      background: "white",
      canEdit: true,
      pageIndex: 0,
      pageCount: 1,
      presenting: false,
      onPrevious: vi.fn(),
      onNext: vi.fn(),
      onRename: vi.fn().mockResolvedValue(undefined),
      onBackground: vi.fn(),
      onExport: vi.fn().mockResolvedValue(undefined),
      onTogglePresenting: vi.fn(),
      menus: [
        { id: "a", label: "أدوات", content: <p>محتوى الأدوات</p> },
        { id: "b", label: "تشجيع", content: <p>محتوى التشجيع</p> },
      ],
    };
    localStorage.clear();
    render(<BoardToolbar {...props} />);
    fireEvent.click(screen.getByRole("button", { name: "افتح لوحة التحكم" })); // folded by default
    expect(screen.queryByText("محتوى الأدوات")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: /أدوات/ }));
    expect(screen.getByText("محتوى الأدوات")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: /تشجيع/ }));
    expect(screen.queryByText("محتوى الأدوات")).toBeNull();
    expect(screen.getByText("محتوى التشجيع")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: /تشجيع/ }));
    expect(screen.queryByText("محتوى التشجيع")).toBeNull();
  });
});
