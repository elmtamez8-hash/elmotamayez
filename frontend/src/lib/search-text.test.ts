import { describe, expect, it } from "vitest";

import { matchesSearch, normaliseSearchText } from "./search-text";

describe("normaliseSearchText", () => {
  it("folds the hamza forms of alef to a bare alef", () => {
    expect(normaliseSearchText("إعلان أول آخر")).toBe("اعلان اول اخر");
  });

  it("folds teh marbuta, alef maqsura and the hamza seats", () => {
    expect(normaliseSearchText("مدرسة")).toBe(normaliseSearchText("مدرسه"));
    expect(normaliseSearchText("مستوى")).toBe(normaliseSearchText("مستوي"));
    expect(normaliseSearchText("مسؤول")).toBe(normaliseSearchText("مسوول"));
    expect(normaliseSearchText("قائمة")).toBe(normaliseSearchText("قايمه"));
  });

  it("drops tashkeel and tatweel", () => {
    expect(normaliseSearchText("مُدَرِّسٌ")).toBe("مدرس");
    expect(normaliseSearchText("جـــبر")).toBe("جبر");
  });

  it("reads Arabic-Indic digits as the Latin ones a keyboard types", () => {
    expect(normaliseSearchText("دورة ٢٠٢٦")).toBe("دوره 2026");
    expect(normaliseSearchText("۱۲")).toBe("12");
  });

  it("lower-cases Latin and collapses whitespace", () => {
    expect(normaliseSearchText("  PDF   ملف \n ")).toBe("pdf ملف");
  });
});

describe("matchesSearch", () => {
  it("matches everything on an empty or blank query", () => {
    expect(matchesSearch("", "أي شيء")).toBe(true);
    expect(matchesSearch("   ", "أي شيء")).toBe(true);
  });

  it("finds a record whatever spelling the keyboard chose", () => {
    expect(matchesSearch("اعلان", "إعلان الاختبار الشهري")).toBe(true);
    expect(matchesSearch("الاختبار الشهريه", "إعلان الاختبار الشهرية")).toBe(true);
  });

  it("needs every word, in any order, across any field", () => {
    expect(matchesSearch("رياضيات الصف", "الصف التاسع", "رياضيات")).toBe(true);
    expect(matchesSearch("رياضيات فيزياء", "الصف التاسع — رياضيات")).toBe(false);
  });

  it("ignores missing fields", () => {
    expect(matchesSearch("جبر", null, undefined, "مقدمة في الجبر")).toBe(true);
  });
});
