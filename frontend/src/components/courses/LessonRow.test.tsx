import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { LessonRow } from "./LessonRow";
import type { CurriculumLesson } from "@/lib/curriculum";

/*
| ⚠️ THE THREE STATES ARE DISTINGUISHED BY TEXT, NOT BY COLOUR (FR-005).
|
| This repository has shipped an invisible state three times by naming a colour
| token `@theme` never defined — Tailwind v4 emits no rule at all for one,
| silently, so `bg-success` was present, correct-looking and painted NOTHING. Each
| time the test that should have caught it was asserting on an `aria-label` over
| an unpainted mark. So these assertions are about words a reader gets either way.
*/

function lesson(overrides: Partial<CurriculumLesson> = {}): CurriculumLesson {
  return {
    uuid: "l-1",
    title: "المعادلات الخطّيّة",
    type: "video",
    type_label: "فيديو",
    family: "uploaded",
    asset_kind: "video",
    is_completable: true,
    duration_seconds: 480,
    state: "open",
    lock: null,
    ...overrides,
  };
}

describe("LessonRow", () => {
  it("names each state in words a screen reader reaches", () => {
    const { rerender } = render(<LessonRow lesson={lesson({ state: "open" })} />);
    expect(screen.getByText("متاح")).toBeTruthy();

    rerender(<LessonRow lesson={lesson({ state: "completed" })} />);
    expect(screen.getByText("مكتمل")).toBeTruthy();

    rerender(
      <LessonRow
        lesson={lesson({
          state: "locked",
          lock: { code: "sequence", message: "أكمِل «المقدّمة» أوّلاً.", blocked_by_title: "المقدّمة" },
        })}
      />,
    );
    expect(screen.getByText("مقفول")).toBeTruthy();
  });

  /*
   | ⚠️ THE DEFECT THIS WHOLE SCREEN EXISTS TO FIX. A locked row that is still an
   | anchor is still openable — by keyboard, by screen reader, by long-press
   | "open in new tab" — and the student learns the lesson is shut only after a
   | page load, from a refusal on something they cannot use.
   |
   | `queryByRole` for both, because a disabled-LOOKING link is exactly what
   | passes a visual check and fails this one.
  */
  it("renders a locked item as neither a link nor a button", () => {
    render(
      <LessonRow
        lesson={lesson({
          state: "locked",
          lock: { code: "exam_pass", message: "اجتز «اختبار الوحدة» أوّلاً.", blocked_by_title: "اختبار الوحدة" },
        })}
      />,
    );

    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("links an open item and a finished one, so a student can go back over it", () => {
    const { rerender } = render(<LessonRow lesson={lesson({ state: "open" })} />);
    expect(screen.getByRole("link").getAttribute("href")).toBe("/learn/l-1");

    // Completed is not closed: re-reading a finished lesson before an exam is
    // the ordinary use of this page.
    rerender(<LessonRow lesson={lesson({ state: "completed" })} />);
    expect(screen.getByRole("link").getAttribute("href")).toBe("/learn/l-1");
  });

  /*
   | ⚠️ COMPLETED AND LOCKED AT ONCE IS A REAL ROW, NOT A CONTRADICTION. The gate
   | never asks whether the target is finished: an expired enrolment refuses every
   | item with `inactive`, and a reorder can put a finished lesson behind
   | unfinished work. Branching on the STATE would render «مكتمل» as a link there
   | — the student taps it and the door refuses, which is the two-answers defect
   | this screen was built to end.
  */
  it("does not link a finished item the door would still refuse", () => {
    render(
      <LessonRow
        lesson={lesson({
          state: "completed",
          lock: {
            code: "inactive",
            message: "تسجيلك في هذا الكورس غير نشط حالياً.",
            blocked_by_title: null,
          },
        })}
      />,
    );

    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.getByText("مكتمل")).toBeTruthy();
    expect(screen.getByText(/غير نشط حالياً/)).toBeTruthy();
  });

  it("shows the reason in the row itself, naming what to go and do", () => {
    render(
      <LessonRow
        lesson={lesson({
          state: "locked",
          lock: {
            code: "sequence",
            message: "أكمِل «المقدّمة» أوّلاً — هذا الكورس متسلسل.",
            blocked_by_title: "المقدّمة",
          },
        })}
      />,
    );

    expect(screen.getByText(/أكمِل «المقدّمة» أوّلاً/)).toBeTruthy();
  });

  /*
   | The server always sends a sentence. This is the row that arrives without one
   | — and a bare «مقفول» with nothing after it is a support ticket, which is the
   | entire reason `LessonAccess` grew a reason in 016.
  */
  it("falls back to a sentence rather than showing a bare «مقفول»", () => {
    render(
      <LessonRow
        lesson={lesson({
          state: "locked",
          lock: { code: "no_seat", message: "", blocked_by_title: null },
        })}
      />,
    );

    expect(screen.getByText(/لم تحجز فيها مقعداً/)).toBeTruthy();
  });
});
