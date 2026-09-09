import { describe, expect, it } from "vitest";

import { reviewDeltas, type PeriodicReview } from "./reviews";

/*
| بلاغُ ٢٠٢٦-٠٩-٠٧: «شهر أغسطس الطالب جاب ٤ من ٥ والشهر اللي قبله ٤٫٣، ومع ذلك
| مكتوب تحسّن ملحوظ». الجملةُ نصُّ المدرّسِ الحرُّ ولا يشتقُّها شيء — والفجوةُ أنّ
| البطاقةَ لم تكن تعرضُ الاتّجاهَ أصلاً، فصارَ النصُّ هو المؤشِّرَ الوحيد.
*/

function review(over: Partial<PeriodicReview> & { uuid: string }): PeriodicReview {
  return {
    period_start: "2026-08-01",
    period_end: "2026-08-31",
    commitment: 4,
    participation: 4,
    homework: 4,
    improvement: 4,
    average: 4,
    note: null,
    is_published: true,
    published_at: null,
    teacher_uuid: "t-1",
    ...over,
  } as PeriodicReview;
}

describe("reviewDeltas", () => {
  it("reads the reported case the way the reader does", () => {
    const rows = [
      review({ uuid: "aug", period_start: "2026-08-01", average: 4 }),
      review({ uuid: "jul", period_start: "2026-07-01", average: 4.25 }),
    ];

    // ٤٫٣ − ٤٫٠ على الشاشة، وهو ما يطرحُه القارئُ بعينِه.
    expect(reviewDeltas(rows).get("aug")).toBe(-0.3);
    // وأقدمُ تقييمٍ لا سابقَ له: حالةٌ لا صفر.
    expect(reviewDeltas(rows).get("jul")).toBeNull();
  });

  it("subtracts what is DISPLAYED, never the raw averages", () => {
    /*
    | ⚠️ الحالةُ الفارقة. ٤٫٢٤ و٤٫١٦ تُعرَضانِ ٤٫٢ و٤٫٢ — رقمانِ متساويانِ على
    | الشاشة. فرقُهما الخامُّ ٠٫٠٨ كان سيُكتَبُ «أقل بـ٠٫١» تحتَهما، وهي شاشةٌ
    | تناقضُ نفسَها. الجوابُ الصادقُ هنا صفر.
    */
    const rows = [
      review({ uuid: "b", period_start: "2026-08-01", average: 4.16 }),
      review({ uuid: "a", period_start: "2026-07-01", average: 4.24 }),
    ];

    expect(reviewDeltas(rows).get("b")).toBe(0);
  });

  it("never compares one teacher's month against another's", () => {
    /*
    | ⚠️ قائمةُ الطالبِ تجمعُ كلَّ من قيَّمَه. بلا تجميعٍ بالمعرِّفِ يقارَنُ
    | «أغسطس عندَ منى» بـ«أغسطس عندَ سامي» فيظهرُ هبوطٌ مخترَعٌ من رأيَينِ لا صلةَ
    | بينهما — وهذه هي الحالةُ التي تسقطُ لو حُذِفَ شرطُ المدرّس.
    */
    const rows = [
      review({ uuid: "mona-aug", period_start: "2026-08-01", average: 4, teacher_uuid: "t-1" }),
      review({ uuid: "sami-aug", period_start: "2026-08-01", average: 2, teacher_uuid: "t-2" }),
      review({ uuid: "mona-jul", period_start: "2026-07-01", average: 3, teacher_uuid: "t-1" }),
    ];

    const deltas = reviewDeltas(rows);

    expect(deltas.get("mona-aug")).toBe(1);
    // أوّلُ تقييمٍ لسامي، مهما سبقَه من صفوفِ غيرِه.
    expect(deltas.get("sami-aug")).toBeNull();
  });

  it("says «unchanged» rather than nothing when the average held", () => {
    const rows = [
      review({ uuid: "b", period_start: "2026-08-01", average: 4 }),
      review({ uuid: "a", period_start: "2026-07-01", average: 4 }),
    ];

    expect(reviewDeltas(rows).get("b")).toBe(0);
  });
});
