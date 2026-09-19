import { describe, expect, it } from "vitest";

import { planDuration, planShape } from "./plans";

/*
| ⛔ **`planDuration` كانت تطبعُ «null يوماً» لمشترٍ يقرأُ سعرَ باقة.**
|
| `null % 30 === 0` تساوي **true** في JavaScript، فالقيمةُ الفارغةُ تعبرُ الشرطَ
| الأوّلَ لفرعِ الأشهرِ ثمّ تسقطُ في الارتدادِ **نصّاً**. والمعامِلُ كانَ مكتوباً
| `number`، فلا يستطيعُ `tsc` أن يراها: نوعُ TypeScript ادّعاءٌ عن الخادمِ لا
| ضمانٌ منه، وهذا المستودعُ دفعَ ثمنَ ذلك قبلاً (تحميلٌ مُقيَّدٌ أجابَ ٢٠٠ باسمٍ
| فارغ).
|
| ⚠️ **وهي غيرُ قابلةٍ للحدوثِ اليوم، وليسَ هذا سببَ الحارس.** `plans.duration_days`
| عمودُ `unsignedInteger` NOT NULL، فالخادمُ لا يملكُ إرسالَها. ومواصفةُ ٠٣٦
| تجعلُ الباقةَ «بالحصصِ أو بالمدّة» — فيومَ يصيرُ العمودُ قابلاً للفراغِ تبدأُ
| كلُّ شاشةٍ تعرضُ باقةً في طباعةِ كلمةِ `null` على مشترٍ، بلا فشلٍ في أيِّ مكان.
*/

describe("planDuration", () => {
  it("names a month rather than counting its days", () => {
    // شهرٌ كلمةُ تسويقٍ والعمودُ أيّام؛ الترجمةُ هنا لا في قاعدةِ البيانات.
    expect(planDuration(30)).toBe("شهر واحد");
    expect(planDuration(60)).toBe("شهران");
    expect(planDuration(90)).toBe("٣ أشهر");
  });

  it("falls back to days when the span is not whole months", () => {
    expect(planDuration(1)).toBe("يوم واحد");
    expect(planDuration(45)).toBe("٤٥ يوماً");
  });

  it.each([
    ["null", null],
    ["undefined", undefined],
    ["zero", 0],
    ["a fraction", 30.5],
    ["NaN", Number.NaN],
  ])("answers nothing rather than printing %s at a buyer", (_label, value) => {
    /*
    | ⚠️ `null` هي الجواب، لا «—». المُنادونَ يصلونَ الأجزاءَ بـ« · »، فعلامةٌ
    | مرئيّةٌ هنا تُقحِمُ شَرطةً في جملة — والخانةُ الوحيدةُ التي تحتاجُ علامةً
    | (تحتَ عنوانِ «المدّة» في جدول) تُوفِّرُها بنفسِها.
    */
    expect(planDuration(value as number)).toBeNull();
  });

  it("never lets a missing duration reach the screen as the word «null»", () => {
    /*
    | التوكيدُ على ما يراه القارئُ لا على الشكلِ الداخليّ: هذه هي الجملةُ التي
    | كانت ستُطبَع، وهي ما يجبُ ألّا يُنتِجَه أيُّ مدخَل.
    */
    for (const value of [null, undefined, Number.NaN, 0]) {
      expect(String(planDuration(value as number))).not.toContain("يوماً");
    }
  });
});

/*
| ٠٣٦ — THE OTHER HALF OF `PlanShapeWordingTest.php`, AND THE TABLE IS THE SAME
| TABLE ON PURPOSE.
|
| ⛔ ONE PLAN HAD TWO NAMES. `planDuration()` above has folded thirty days into
| «شهر واحد» since ٠٣٤; the SERVER said «٣٠ يوماً» — so the buyer read one
| sentence, the officer pricing that same plan read another, and the notification
| reaching the teacher by name carried the second. A teacher who opens their own
| page after that notification finds a different sentence and reads two rows.
|
| ⚠️ THERE IS NO CONTRACT BETWEEN THE TWO LANGUAGES, so the only way to hold one
| spelling is an identical table on both sides. A single copy in one place proves
| only that that side agrees with itself. **A change to either table moves to the
| other in the same commit.**
|
| ⚠️ AND THE CASES SIT ON CLDR BAND EDGES, not on comfortable numbers. 12 and 30
| are exactly the band a template literal happens to agree with, so a table built
| from them alone is green over a build that knows no Arabic at all.
*/

/** [duration_days, session_count, the one sentence both sides must produce] */
const SHAPE_CASES: Array<[number | null, number | null, string | null]> = [
  [1, null, "يوم واحد"],
  [2, null, "يومان"],
  [7, null, "٧ أيّام"],
  [11, null, "١١ يوماً"],

  [30, null, "شهر واحد"],
  [60, null, "شهران"],
  [90, null, "٣ أشهر"],
  [330, null, "١١ شهراً"],

  [null, 1, "حصّة واحدة"],
  [null, 2, "حصّتان"],
  [null, 12, "١٢ حصّة"],

  // ⚠️ A half-written row is not «٠ يوماً» — the caller drops it.
  [null, null, null],
  [0, 0, null],
];

describe("a plan is named the same way on both sides", () => {
  it.each(SHAPE_CASES)("(%s days, %s sessions) reads %s", (duration_days, session_count, expected) => {
    expect(planShape({ duration_days, session_count })).toBe(expected);
  });

  it("prefers the sessions it was sold by over a duration left on the row", () => {
    // ⚠️ The order is part of the answer — an older row may carry both, and the
    // two sides must not differ by which one they happened to read first.
    expect(planShape({ duration_days: 30, session_count: 12 })).toBe("١٢ حصّة");
  });
});
