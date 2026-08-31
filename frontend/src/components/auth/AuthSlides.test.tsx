import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AuthSlides } from "./AuthSlides";

/*
| العارضُ يتقدّمُ وحدَه، وذلك هو ما يمكنُ أن ينكسرَ بصمت.
|
| ⚠️ التحديثُ دالّيٌّ عمداً: `setIndex(index + 1)` يُغلِقُ على الفهرسِ الذي عملَ
| به التأثير، فيتحرّكُ العارضُ من ٠ إلى ١ ويقفُ هناك للأبد — عارضٌ بشريحتَين
| يبدو أنّه يعمل. لذا يقيسُ الاختبارُ **دورتَين** لا واحدة.
|
| ⚠️ و`fireEvent` لا `userEvent`: الثاني ينتظرُ مؤقّتاتٍ حقيقيّةً بين خطواته، فيعلَّقُ
| تحتَ `useFakeTimers` على ساعةٍ لا يُحرّكُها شيء — القاعدةُ التي سجّلها
| `ConfirmButton` قبلَه.
*/

const SLIDES = [
  { title: "الأولى", body: "نصّ الأولى" },
  { title: "الثانية", body: "نصّ الثانية" },
  { title: "الثالثة", body: "نصّ الثالثة" },
];

beforeEach(() => {
  vi.useFakeTimers();
  // jsdom has no matchMedia; the component reads it for prefers-reduced-motion.
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia;
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

const tick = (ms: number) => act(() => void vi.advanceTimersByTime(ms));

describe("AuthSlides", () => {
  it("advances on its own, and keeps advancing", () => {
    render(<AuthSlides slides={SLIDES} />);

    expect(screen.getByText("نصّ الأولى")).toBeDefined();

    tick(6000);
    expect(screen.getByText("نصّ الثانية")).toBeDefined();

    // الدورةُ الثانيةُ هي ما يسقطُ على تحديثٍ غيرِ دالّيّ.
    tick(6000);
    expect(screen.getByText("نصّ الثالثة")).toBeDefined();

    // ويلتفُّ.
    tick(6000);
    expect(screen.getByText("نصّ الأولى")).toBeDefined();
  });

  it("jumps to the slide its dot names", () => {
    render(<AuthSlides slides={SLIDES} />);

    fireEvent.click(screen.getByRole("button", { name: /الشريحة ٣|الشريحة 3/ }));

    expect(screen.getByText("نصّ الثالثة")).toBeDefined();
  });

  it("holds still while a pointer is on it", () => {
    render(<AuthSlides slides={SLIDES} />);

    fireEvent.mouseEnter(screen.getByRole("group"));
    tick(20000);

    expect(screen.getByText("نصّ الأولى")).toBeDefined();
  });

  /*
  | ⚠️ الحركةُ مطفأةٌ لمن طلبَ ذلك. لوحةٌ تُغيّرُ نفسَها كلَّ ستِّ ثوانٍ بجوارِ حقلِ
  | كلمةِ مرورٍ هي بالضبطِ ما وُجِدَ ذلك الإعدادُ لإيقافه — والنقاطُ تبقى عاملةً،
  | فالمحتوى لا يُفقَد.
  */
  it("does not move itself under prefers-reduced-motion", () => {
    window.matchMedia = ((query: string) => ({
      matches: true,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;

    render(<AuthSlides slides={SLIDES} />);
    tick(20000);

    expect(screen.getByText("نصّ الأولى")).toBeDefined();
  });
});
