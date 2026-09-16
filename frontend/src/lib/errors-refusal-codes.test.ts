import { describe, expect, it } from "vitest";

import { ApiError } from "./api";
import { userMessage } from "./errors";

/*
| ⛔ **«ليس لك» ليست «غير موجود»، والـ٤٠٤ كان يقول الثانية عن الأولى.**
|
| بلاغُ ٢٠٢٦-٠٩-١٦: مالكُ المنصّةِ فتحَ درساً لا تسجيلَ له فيه، وفتحَ كشفَ
| تسويةٍ لا ملفَّ تدريسٍ له — فقيلَ له في المرّتَينِ إنَّ المحتوى **حُذِف**.
| والخادمُ كانَ يقولُ الصوابَ في الأولى، و`userMessage()` يطرحُ نصَّ كلِّ ٤٠٤.
|
| ⚠️ **وطرحُ النصِّ حارسٌ صحيحٌ ويبقى**: ٤٠٤ الإطاريُّ إنجليزيٌّ («No query
| results for model …») ولا يصلحُ لشاشة. فالرمزُ هو الآليّة، لا تمريرُ النصّ —
| والشقُّ الأخيرُ هو الذي يُثبِتُ أنَّ الحارسَ لم يُفتَحْ في الطريق.
*/
function refusal(status: number, body: unknown): ApiError {
  return new ApiError("raw", status, body);
}

describe("a 404 that says which refusal it is", () => {
  it("names the enrolment on a lesson that is not the reader's", () => {
    const said = userMessage(refusal(404, { message: "…", code: "not_enrolled" }));

    expect(said).toContain("ليس ضمن كورساتك");
    expect(said).not.toContain("حُذف");
  });

  it("names the missing teaching profile on the settlement statement", () => {
    const said = userMessage(refusal(404, { message: "…", code: "no_teacher_profile" }));

    expect(said).toContain("ملفُّ تدريس");
    expect(said).not.toContain("حُذف");
  });

  it("still refuses to print a server 404's own words when there is no code", () => {
    const said = userMessage(
      refusal(404, { message: "No query results for model [Lesson]." }),
    );

    expect(said).toBe("العنصر المطلوب غير موجود أو حُذف.");
  });
});
