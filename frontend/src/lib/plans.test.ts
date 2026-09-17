import { describe, expect, it } from "vitest";

import { planDuration } from "./plans";

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
    expect(planDuration(90)).toBe("3 أشهر");
  });

  it("falls back to days when the span is not whole months", () => {
    expect(planDuration(1)).toBe("يوم واحد");
    expect(planDuration(45)).toBe("45 يوماً");
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
