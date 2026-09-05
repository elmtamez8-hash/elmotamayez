import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CohortList } from "./CohortList";
import { TONE_CLASSES } from "@/lib/labels";
import type { CohortSummary } from "@/lib/public-api";

/*
| Spec 023 · US2 — the three states, and the two numbers that are not numbers.
|
| ⚠️ THE BADGE IS ASSERTED ON ITS CLASSES AS WELL AS ITS TEXT. A colour class
| naming a token `@theme` never defined paints NOTHING — Tailwind v4 emits no
| rule for it — and this tree has shipped that four times: an invisible speaking
| dot, an ink ring where a green one was meant, «إجابة معتمَدة» in body text, and
| an avatar circle with a letter floating in it. Every one of those had a passing
| test that asserted the label over an unpainted mark. There is no `success`
| COLOUR here; `success` is a tone name that `TONE_CLASSES` maps onto real
| tokens, and that is what is compared.
*/
const base: CohortSummary = {
  uuid: "aaaa0000-0000-4000-8000-000000000001",
  name: "مجموعة السبت",
  description: null,
  status: "open",
  schedule: ["السبت 16:00"],
  seats_left: 5,
  is_joinable: true,
};

/** Every render needs the course the buttons link into (027 · FR-001). */
const COURSE = "cccc0000-0000-4000-8000-000000000001";

function badgeOf(name: string): HTMLElement | null {
  return screen.getByText(name).closest("li")?.querySelector("span") ?? null;
}

describe("CohortList", () => {
  it("names each of the three states", () => {
    render(
      <CohortList
        courseUuid={COURSE}
        cohorts={[
          { ...base, uuid: "1", name: "أ", status: "open" },
          { ...base, uuid: "2", name: "ب", status: "full", seats_left: 0, is_joinable: false },
          { ...base, uuid: "3", name: "ج", status: "closed", is_joinable: false },
        ]}
      />,
    );

    expect(badgeOf("أ")?.textContent).toBe("مفتوحة");
    expect(badgeOf("ب")?.textContent).toBe("ممتلئة");
    expect(badgeOf("ج")?.textContent).toBe("مغلقة");
  });

  it("paints each badge with tokens that exist", () => {
    render(<CohortList courseUuid={COURSE} cohorts={[{ ...base, name: "أ", status: "open" }]} />);

    // Compared against the map rather than against a literal class string: a
    // literal here would be a second copy of the palette, and it would agree
    // with itself while the component painted nothing.
    for (const cls of TONE_CLASSES.success.split(" ")) {
      expect(badgeOf("أ")?.className).toContain(cls);
    }
  });

  it("says «no seats» rather than falling silent at zero", () => {
    render(<CohortList courseUuid={COURSE} cohorts={[{ ...base, seats_left: 0 }]} />);

    // `!seats_left` swallows the zero, and the group a visitor most needs to be
    // warned about would then read as «unlimited».
    expect(screen.getByText("لا مقاعد متاحة")).toBeTruthy();
  });

  it("prints no seat line at all for a group with no ceiling", () => {
    const { seats_left: _omitted, ...unlimited } = base;

    render(<CohortList courseUuid={COURSE} cohorts={[unlimited]} />);

    // «غير محدود» is not a quantity: the key is absent and nothing is printed,
    // rather than a zero that reads as full.
    expect(screen.queryByText(/مقاعد|مقعد/)).toBeNull();
  });

  it("counts seats in the four Arabic bands", () => {
    render(
      <CohortList
        courseUuid={COURSE}
        cohorts={[
          { ...base, uuid: "1", name: "أ", seats_left: 1 },
          { ...base, uuid: "2", name: "ب", seats_left: 2 },
          { ...base, uuid: "3", name: "ج", seats_left: 4 },
          { ...base, uuid: "4", name: "د", seats_left: 12 },
        ]}
      />,
    );

    // 11+ returns to the SINGULAR — «١٢ مقعداً», never «١٢ مقاعد». The dual is
    // its own word and is not «٢ مقاعد».
    expect(screen.getByText("مقعد واحد متبقٍ")).toBeTruthy();
    expect(screen.getByText("مقعدان متبقّيان")).toBeTruthy();
    expect(screen.getByText("٤ مقاعد متبقّية")).toBeTruthy();
    expect(screen.getByText("١٢ مقعداً متبقّياً")).toBeTruthy();
  });

  it("says so out loud when nothing is scheduled yet", () => {
    render(<CohortList courseUuid={COURSE} cohorts={[{ ...base, schedule: [] }]} />);

    // An empty gap reads as a broken section rather than as an answer.
    expect(screen.getByText("لم تُجدول حصص بعد")).toBeTruthy();
  });

  /*
  | Spec 027 · FR-001 · FR-002 — the invitation, and its ABSENCE.
  |
  | ⚠️ ABSENT, NEVER DISABLED. A greyed-out button on a full group promises a
  | place that is not coming; the card already says «ممتلئة», and a dead control
  | beside that word only invites a press. So the assertion is that no control
  | exists at all — a disabled one would satisfy «cannot be clicked» and still be
  | the wrong screen.
  |
  | ⚠️ AND THE PREDICATE IS `is_joinable`, THE SERVER'S OWN. The last case proves
  | it is not `status === "open"` rebuilt here: a group that reads «مفتوحة» while
  | the server says it cannot be joined gets no button, because the purchase route
  | would refuse it.
  */
  it("offers a subscribe invitation on a joinable group", () => {
    render(<CohortList courseUuid={COURSE} cohorts={[base]} />);

    const link = screen.getByRole("link", { name: "اشترك في هذه المجموعة" });

    expect(link.getAttribute("href")).toBe(
      `/subscribe?course=${COURSE}&cohort=${base.uuid}`,
    );
  });

  it("draws NO control at all on a group that cannot be joined", () => {
    render(
      <CohortList
        courseUuid={COURSE}
        cohorts={[{ ...base, status: "full", seats_left: 0, is_joinable: false }]}
      />,
    );

    expect(screen.queryByRole("link", { name: "اشترك في هذه المجموعة" })).toBeNull();
    expect(screen.queryByRole("button", { name: "اشترك في هذه المجموعة" })).toBeNull();
  });

  it("reads the server's verdict rather than re-deriving it from the status", () => {
    render(
      <CohortList
        courseUuid={COURSE}
        cohorts={[{ ...base, status: "open", is_joinable: false }]}
      />,
    );

    expect(screen.queryByRole("link", { name: "اشترك في هذه المجموعة" })).toBeNull();
  });
});