import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { CohortSwitcher } from "./CohortSwitcher";
import type { CohortsForCourse } from "@/lib/cohorts";

/*
| ⛔ 036 · T098 · T118 · FR-019 — «تُعرَض ويُختار منها، ومكتوب بجوارها أنّها
| ليست معروضة للبيع».
|
| The requirement was UNIMPLEMENTABLE, not merely unimplemented: this picker
| built its destinations out of the student PICKER's list, which 036 taught to
| DROP a group no live price reaches, and then filtered those again on
| `is_joinable`, which 036 taught to ask the price too. «Open, has room, not on
| sale» — the exact case to be marked — was deleted twice over before the
| component ran.
|
| ⚠️ SO THE ASSERTION IS THAT THE OPTION IS PRESENT AND CARRIES THE WORDS. A
| test that only checked the words would pass over a list that never offers the
| row, and one that only counted options would pass over a row offered with
| nothing said about it.
*/
vi.mock("@/lib/cohorts", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/cohorts")>();

  return { ...actual, cohorts: { ...actual.cohorts } };
});

const STATE: CohortsForCourse = {
  membership: { cohort_uuid: "mine", cohort_name: "مجموعة السبت", joined_at: "2026-09-01T00:00:00Z" },
  past_cohorts: [],
  pending_request: null,
  // ⚠️ DELIBERATELY EMPTY. The picker list is where the destinations used to
  // come from; a component reading it instead would find nothing here and
  // render «لا توجد مجموعة أخرى», which is the defect wearing a polite message.
  cohorts: [],
  transfer_destinations: [
    { uuid: "mine", name: "مجموعة السبت", schedule_preview: ["السبت ٤م"], is_on_sale: true },
    { uuid: "sunday", name: "مجموعة الأحد", schedule_preview: ["الأحد ٦م"], is_on_sale: true },
    { uuid: "quiet", name: "مجموعة الثلاثاء", schedule_preview: [], is_on_sale: false },
  ],
};

describe("asking to move to another group", () => {
  it("offers a destination that is open but not on sale, and says so", () => {
    render(<CohortSwitcher state={STATE} onChanged={() => undefined} />);

    const options = screen.getAllByRole("option").map((option) => option.textContent);

    expect(options).toContain("مجموعة الأحد — الأحد ٦م");

    // ⛔ PRESENT **AND** LABELLED. An officer can still approve a move into an
    // unpriced group, so hiding it makes the question unaskable.
    expect(options).toContain("مجموعة الثلاثاء — غير معروضة للبيع");
  });

  it("never offers the group the reader is already in", () => {
    render(<CohortSwitcher state={STATE} onChanged={() => undefined} />);

    const options = screen.getAllByRole("option").map((option) => option.textContent);

    // The exclusion is the CALLER's, because the read answers about the course:
    // «انتقل إلى المجموعة التي أنت فيها» is an option that can only confuse.
    expect(options.some((label) => label?.startsWith("مجموعة السبت"))).toBe(false);
  });
});
