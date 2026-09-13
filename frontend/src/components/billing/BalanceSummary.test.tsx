import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";

import { BalanceSummary } from "@/components/billing/BalanceSummary";
import type { CreditBalance } from "@/lib/billing";

/*
| ٠٣٥ · T068 — «كم أملك» و«كم أستطيعُ أن أحجزَ به» سؤالانِ لا سؤال.
|
| ⛔ والتوكيدُ على الرقمِ الكبيرِ نفسِه. بطاقةٌ تعرضُ المملوكَ وحدَه هي كيفَ يقرأُ
| الطالبُ «٣ حصص» هنا ثمّ يُرفَضُ حجزُه بعدَ شاشةٍ واحدةٍ بلا شيءٍ يربطُ الاثنَين —
| وهو الرفضُ الذي يُقرَأُ عطباً في المنتَج (FR-013). والمجمَّدُ يُسمّى بجانبَه،
| والمملوكُ يبقى معروضاً فلا يبدو أنّ شيئاً ضاع.
|
| ⚠️ ولا شيءَ من هذا يراهُ pest: هذه أسئلةٌ عن ما يُرسَمُ في متصفّح. وهذا الملفُّ
| هو أوّلُ اختبارٍ لهذه البطاقةِ أصلاً — وقد كانت، قبلَه، شاشةَ الأرصدةِ كلَّها بلا
| توكيدٍ واحد.
*/

function balance(overrides: Partial<CreditBalance> = {}): CreditBalance {
  return {
    uuid: "b-1",
    course: { uuid: "c-1", title: "الرياضيات", teacher_name: "أكاديمية النور" },
    purchased_credits: 10,
    consumed_credits: 4,
    remaining_credits: 6,
    held_credits: 0,
    available_credits: 6,
    credit_limit_credits: 0,
    is_withheld: false,
    credits_needed: 0,
    ...overrides,
  };
}

describe("BalanceSummary", () => {
  it("leads with what can be booked, not with what is owned", () => {
    render(<BalanceSummary balances={[balance({ remaining_credits: 9, held_credits: 2, available_credits: 7 })]} />);

    expect(screen.getByText("حصة متاحة للحجز")).toBeTruthy();
    // ⚠️ الرقمُ نفسُه، لا وجودُ العنصر: عرضُ ٩ هنا هو بالضبط العطبُ الذي تمنعُه
    // هذه الحالة، والبطاقةُ تمرُّ على أيِّ توكيدٍ يعدُّ العناصر.
    expect(screen.getByText("٧")).toBeTruthy();
  });

  it("names the frozen credits instead of letting them go missing", () => {
    render(<BalanceSummary balances={[balance({ remaining_credits: 9, held_credits: 2, available_credits: 7 })]} />);

    expect(screen.getByText(/مجمَّدة/)).toBeTruthy();
    // المملوكُ باقٍ على البطاقة، وإلّا بدا أنّ حصّتَين اختفتا.
    expect(screen.getByText("المملوك")).toBeTruthy();
  });

  it("says nothing about freezing when nothing is frozen", () => {
    // ⚠️ جملةٌ تظهرُ دائماً هي ضجيجٌ يتعلّمُ القارئُ تجاهلَه، فتذهبُ معها الحالةُ
    // التي كُتِبَت لها.
    render(<BalanceSummary balances={[balance()]} />);

    expect(screen.queryByText(/مجمَّدة/)).toBeNull();
  });
});
