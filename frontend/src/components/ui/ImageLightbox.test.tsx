import { act, fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { ImageLightbox, useLightboxIn, ViewableImage, type LightboxImage } from "./ImageLightbox";

/*
| The page's own image viewer (owner decision 2026-09-28: never a new tab).
|
| ⚠️ jsdom has no `showModal()`; `vitest.setup.ts` shims it. The focus trap and
| the inert page behind are the browser's and are NOT measured here — what is
| measured is everything this component decides: how it opens and closes, which
| picture the arrows reach in a right-to-left page, where focus goes back to,
| the page's scroll, and the zoom.
*/

const PICTURES: LightboxImage[] = [
  { src: "https://files.test/1.png", alt: "الأولى" },
  { src: "https://files.test/2.png", alt: "الثانية" },
  { src: "https://files.test/3.png", alt: "الثالثة" },
];

function Harness({ start = null }: { start?: number | null }) {
  const [index, setIndex] = useState<number | null>(start);

  return (
    <>
      <button type="button" onClick={() => setIndex(1)}>
        افتح
      </button>
      <ImageLightbox images={PICTURES} index={index} onIndexChange={setIndex} onClose={() => setIndex(null)} />
    </>
  );
}

const dialog = () => document.querySelector("dialog");
const shown = () => screen.getByRole("img").getAttribute("alt");

describe("ImageLightbox", () => {
  beforeEach(() => {
    document.documentElement.dir = "rtl";
  });

  afterEach(() => {
    document.documentElement.removeAttribute("dir");
    document.documentElement.style.overflow = "";
  });

  function openFromButton() {
    render(<Harness />);
    const trigger = screen.getByRole("button", { name: "افتح" });

    trigger.focus();
    fireEvent.click(trigger);

    return trigger;
  }

  it("opens as a modal on the chosen picture and locks the page's scroll", () => {
    openFromButton();

    expect(dialog()?.open).toBe(true);
    expect(shown()).toBe("الثانية");
    expect(screen.getByText("٢ من ٣")).toBeTruthy();
    expect(document.documentElement.style.overflow).toBe("hidden");
    // Focus lands on the way out.
    expect(document.activeElement).toBe(screen.getByRole("button", { name: "إغلاق عارض الصور" }));
  });

  it("closes with the button, and gives focus and the scroll back", () => {
    const trigger = openFromButton();

    fireEvent.click(screen.getByRole("button", { name: "إغلاق عارض الصور" }));

    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(trigger);
    expect(document.documentElement.style.overflow).toBe("");
  });

  it("closes with Escape", () => {
    openFromButton();

    fireEvent.keyDown(dialog() as HTMLDialogElement, { key: "Escape" });

    expect(dialog()).toBeNull();
  });

  it("closes on the backdrop, and never on the picture itself", () => {
    openFromButton();

    // The picture: a zoom, not a close.
    fireEvent.click(screen.getByRole("button", { name: "تكبير الصورة" }));
    expect(dialog()).not.toBeNull();

    fireEvent.click(dialog() as HTMLDialogElement);
    expect(dialog()).toBeNull();
  });

  it("walks the pictures right-to-left: ArrowLeft is «التالي», ArrowRight «السابق»", () => {
    openFromButton();
    const box = dialog() as HTMLDialogElement;

    fireEvent.keyDown(box, { key: "ArrowLeft" });
    expect(shown()).toBe("الثالثة");

    // The last picture: no further.
    fireEvent.keyDown(box, { key: "ArrowLeft" });
    expect(shown()).toBe("الثالثة");
    expect(screen.getByRole("button", { name: "الصورة التالية" })).toHaveProperty("disabled", true);

    fireEvent.keyDown(box, { key: "ArrowRight" });
    fireEvent.keyDown(box, { key: "ArrowRight" });
    expect(shown()).toBe("الأولى");
    expect(screen.getByRole("button", { name: "الصورة السابقة" })).toHaveProperty("disabled", true);
  });

  it("puts «التالي» at the end of the line, which is the left in Arabic", () => {
    openFromButton();

    const next = screen.getByRole("button", { name: "الصورة التالية" });
    const previous = screen.getByRole("button", { name: "الصورة السابقة" });

    // Logical sides: `end` is the left edge in a right-to-left page.
    expect(next.className).toContain("end-3");
    expect(previous.className).toContain("start-3");

    fireEvent.click(next);
    expect(shown()).toBe("الثالثة");

    fireEvent.click(previous);
    fireEvent.click(previous);
    expect(shown()).toBe("الأولى");
  });

  it("walks the other way on a left-to-right page", () => {
    document.documentElement.dir = "ltr";
    openFromButton();

    fireEvent.keyDown(dialog() as HTMLDialogElement, { key: "ArrowRight" });

    expect(shown()).toBe("الثالثة");
  });

  it("toggles the zoom with a press on the picture, and every picture opens fitted", () => {
    openFromButton();

    fireEvent.click(screen.getByRole("button", { name: "تكبير الصورة" }));
    const zoomed = screen.getByRole("button", { name: "تصغير الصورة" });

    expect(zoomed.getAttribute("aria-pressed")).toBe("true");

    fireEvent.keyDown(dialog() as HTMLDialogElement, { key: "ArrowLeft" });
    expect(screen.getByRole("button", { name: "تكبير الصورة" }).getAttribute("aria-pressed")).toBe("false");
  });

  it("zooms on a two-finger pinch", () => {
    openFromButton();

    const stage = (dialog() as HTMLDialogElement).querySelector("[data-lightbox-stage]") as HTMLElement;

    fireEvent.pointerDown(stage, { pointerId: 1, clientX: 100, clientY: 100 });
    fireEvent.pointerDown(stage, { pointerId: 2, clientX: 200, clientY: 100 });
    fireEvent.pointerMove(stage, { pointerId: 2, clientX: 300, clientY: 100 });

    expect(screen.getByRole("button", { name: "تصغير الصورة" })).toBeTruthy();
  });

  it("says so when a picture will not load", () => {
    openFromButton();

    fireEvent.error(screen.getByRole("img"));

    expect(screen.getByText("تعذّر تحميل الصورة.")).toBeTruthy();
  });
});

describe("ViewableImage", () => {
  it("opens its one picture from a labelled press", () => {
    render(
      <ViewableImage src="https://files.test/me.png" alt="صورة المدرّس">
        <img src="https://files.test/me.png" alt="" />
      </ViewableImage>,
    );

    fireEvent.click(screen.getByRole("button", { name: "عرض الصورة مكبّرة: صورة المدرّس" }));

    expect(dialog()?.open).toBe(true);
    expect(screen.queryByText(/من/)).toBeNull();
  });
});

describe("useLightboxIn", () => {
  function Body({ html }: { html: string }) {
    const { body, lightbox } = useLightboxIn<HTMLDivElement>(html);

    return (
      <>
        <div {...body} />
        {lightbox}
      </>
    );
  }

  const HTML =
    '<p>مقدّمة</p><p><img src="https://files.test/a.png" alt="أ"></p><p>نص</p><p><img src="https://files.test/b.png" alt="ب"></p>';

  it("opens the pressed picture of a rendered body, and the arrows walk that body's pictures", () => {
    const { container } = render(<Body html={HTML} />);
    const pictures = container.querySelectorAll("img");

    expect(pictures[1].getAttribute("role")).toBe("button");
    expect(pictures[1].getAttribute("tabindex")).toBe("0");

    act(() => {
      fireEvent.click(pictures[1]);
    });

    expect(dialog()?.open).toBe(true);
    expect(screen.getByText("٢ من ٢")).toBeTruthy();

    fireEvent.keyDown(dialog() as HTMLDialogElement, { key: "ArrowRight" });
    expect(screen.getByText("١ من ٢")).toBeTruthy();
  });

  it("keeps the pictures pressable through a re-render of the page", () => {
    const { container, rerender } = render(<Body html={HTML} />);

    rerender(<Body html={HTML} />);

    const second = container.querySelectorAll("img")[1];

    expect(second.getAttribute("role")).toBe("button");

    fireEvent.click(second);
    expect(screen.getByText("٢ من ٢")).toBeTruthy();
  });

  it("opens from the keyboard too", () => {
    const { container } = render(<Body html={HTML} />);

    fireEvent.keyDown(container.querySelectorAll("img")[0], { key: "Enter" });

    expect(screen.getByText("١ من ٢")).toBeTruthy();
  });
});
