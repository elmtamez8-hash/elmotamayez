import { describe, expect, it } from "vitest";

import { formatSessionDay, sessionDayKey } from "./session-format";

/*
| اليومُ الذي تقعُ فيه الحصّةُ يُقرأُ بمنطقةِ الخادم، لا بمنطقةِ الجهاز.
|
| «جدولي» صارَ مجمَّعاً بالأيّام، فالسؤالُ «تحتَ أيِّ عنوانٍ تقعُ هذه البطاقة؟» صارَ
| له جوابٌ يمكنُ أن يكونَ خاطئاً — وحصّةٌ الساعةَ الواحدةَ صباحاً بتوقيتِ الدوحة
| تقعُ تحتَ «أمس» لطالبٍ حاسوبُه على منطقةٍ أخرى. نفسُ العيبِ الذي وُجِدت
| `formatSessionTime` لمنعِه، مرفوعاً درجةً.
*/

const QATAR = "Asia/Qatar"; // UTC+3، بلا توقيتٍ صيفيّ.

describe("sessionDayKey", () => {
  it("reads the day in the session's zone, not the machine's", () => {
    /*
     | ⚠️ الاختبارُ كلُّه هذا السطر. 22:30 UTC هو 01:30 من اليومِ التالي في الدوحة،
     | فمفتاحُ اليومِ ٢٨ لا ٢٧. أيُّ تنفيذٍ يقصُّ نصَّ ISO أو يقرأُ منطقةَ الجهاز
     | يُجيبُ ٢٧ — ويضعُ حصّةَ الليلةِ تحتَ عنوانِ الأمس.
     */
    expect(sessionDayKey("2026-08-27T22:30:00+00:00", QATAR)).toBe("2026-08-28");
  });

  it("sorts as a string, which is why it is written this way round", () => {
    // A key that sorted as text but not as a date would put September before
    // August the first time a group list is ordered by it.
    expect(sessionDayKey("2026-08-27T09:00:00+00:00", QATAR) < sessionDayKey("2026-09-01T09:00:00+00:00", QATAR)).toBe(true);
  });
});

describe("formatSessionDay", () => {
  const now = new Date("2026-08-27T09:00:00+00:00"); // 12:00 الدوحة

  it("calls today today and tomorrow tomorrow", () => {
    expect(formatSessionDay("2026-08-27T17:00:00+00:00", QATAR, now)).toBe("اليوم");
    expect(formatSessionDay("2026-08-28T06:00:00+00:00", QATAR, now)).toBe("غداً");
  });

  it("names any other day rather than counting to it", () => {
    // «بعد ٣ أيام» is a number the reader has to turn back into a weekday before
    // they can put it beside anything else in their week.
    expect(formatSessionDay("2026-08-30T06:00:00+00:00", QATAR, now)).toContain("أغسطس");
  });

  it("decides today by the session's zone too", () => {
    /*
     | ⚠️ الحدُّ نفسُه من الجهةِ الأخرى: 21:30 UTC يومَ ٢٧ هو ٢٨ في الدوحة، فهي
     | «غداً» وليست «اليوم» — رغمَ أنّ تاريخَها بالـUTC هو تاريخُ اليوم.
     */
    expect(formatSessionDay("2026-08-27T21:30:00+00:00", QATAR, now)).toBe("غداً");
  });
});
