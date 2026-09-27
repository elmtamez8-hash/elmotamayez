import { describe, expect, it } from "vitest";

import {
  attendanceBar,
  cancellationWindow,
  chatLimitSentence,
  creditCeilingSentence,
  deferredLadderSentence,
  dormancyPeriod,
  minutesAsWords,
  offboardingSentence,
  renewalSentence,
  reviewSentence,
  stopSellingSentence,
  storeRefundSentence,
  twoFactorSentence,
} from "./legal-terms";

describe("legal-terms — the shipped defaults read as the pages used to", () => {
  it("words each default in Arabic numerals", () => {
    expect(twoFactorSentence(14)).toContain("بعد مهلة ١٤ يوماً");
    expect(creditCeilingSentence(24)).toContain("على ٢٤ حصة");
    expect(stopSellingSentence(60)).toContain("منذ ٦٠ يوماً");
    expect(dormancyPeriod(12)).toBe("١٢ شهراً");
    expect(cancellationWindow(1440)).toBe("٢٤ ساعة");
    expect(attendanceBar(50)).toBe("نصف مدّة الحصة");
    expect(renewalSentence(3)).toContain("قبل ٣ أيام من انتهائه");
    expect(chatLimitSentence(30)).toContain("٣٠ رسالة في الدقيقة");
    expect(reviewSentence(4, 30)).toContain("٤ حصص، مرةً كل ٣٠ يوماً");
    expect(offboardingSentence(30)).toContain("يُمهَل المدرّس ٣٠ يوماً");
    expect(storeRefundSentence(48)).toContain("خلال ٤٨ ساعة من الشراء");
  });

  it("words the deferred-payment ladder in one sentence", () => {
    expect(
      deferredLadderSentence({
        initial: 1,
        increaseAfterOnTime: 3,
        increaseBy: 1,
        max: 4,
        resetAfterLateDays: 14,
      }),
    ).toBe(
      "يبدأ الحدّ المسموح بحصةٍ واحدة، ويزيد حصةً بعد كل ٣ دفعات في موعدها حتى ٤ حصص. وإن بقي رصيدك بالسالب أكثر من ١٤ يوماً يعود الحدّ إلى صفر وتصير الحصص بالدفع المسبق.",
    );
  });
});

describe("legal-terms — an operator's other numbers", () => {
  it("agrees the noun with one, two and eleven", () => {
    expect(stopSellingSentence(2)).toContain("منذ يومين");
    expect(creditCeilingSentence(1)).toContain("على حصة واحدة");
    expect(creditCeilingSentence(11)).toContain("على ١١ حصة");
    expect(reviewSentence(2, 1)).toContain("بعد أن تحضر عنده حصتين، مرةً كل يوم.");
  });

  it("says a window in the largest unit that divides it", () => {
    expect(minutesAsWords(720)).toBe("١٢ ساعة");
    expect(minutesAsWords(120)).toBe("ساعتين");
    expect(minutesAsWords(90)).toBe("٩٠ دقيقة");
  });

  it("says a bar other than half as a percentage", () => {
    expect(attendanceBar(75)).toBe("٧٥٪ من مدّة الحصة");
  });

  it("drops the growth clause when the ceiling cannot grow", () => {
    expect(
      deferredLadderSentence({ initial: 2, increaseAfterOnTime: 3, increaseBy: 0, max: 4, resetAfterLateDays: null }),
    ).toBe("يبدأ الحدّ المسموح بحصتين.");
  });

  it("words a zero as a real setting", () => {
    expect(twoFactorSentence(0)).toContain("من أول دخول");
    expect(renewalSentence(0)).toBe("لا يتجدّد الاشتراك تلقائياً. وعند انتهائه يُغلق ما كان يفتحه.");
  });
});

describe("legal-terms — a number the API did not send", () => {
  it("leaves the sentence out rather than guess", () => {
    expect(twoFactorSentence(null)).toBeNull();
    expect(creditCeilingSentence(null)).toBeNull();
    expect(stopSellingSentence(null)).toBeNull();
    expect(dormancyPeriod(null)).toBeNull();
    expect(cancellationWindow(null)).toBeNull();
    expect(attendanceBar(null)).toBeNull();
    expect(reviewSentence(4, null)).toBeNull();
    expect(storeRefundSentence(null)).toBeNull();
    expect(
      deferredLadderSentence({ initial: null, increaseAfterOnTime: 3, increaseBy: 1, max: 4, resetAfterLateDays: 14 }),
    ).toBeNull();
  });

  it("keeps the part of a sentence that needs no number", () => {
    expect(chatLimitSentence(null)).toBe("يمكنك الإبلاغ عن أي رسالة أو تقييم.");
    expect(offboardingSentence(null)).toContain("لا تكتمل مغادرة المدرّس قبل تسوية مستحقاته.");
    expect(renewalSentence(null)).not.toMatch(/[٠-٩]/);
  });
});
