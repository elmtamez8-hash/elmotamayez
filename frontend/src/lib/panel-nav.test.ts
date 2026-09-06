import { describe, expect, it } from "vitest";

import { allowedNav, mainNav, quickAccessFor } from "./panel-nav";
import { P } from "./permissions";
import type { User } from "./types";

/*
| «المنيو بيتغير حسب هو طالب او مدرس او ادمن او ولي امر» — طلبُ ٢٠٢٦-٠٩-٠٦.
|
| ⚠️ والقاعدةُ الحاملةُ ليست «لكلِّ دَورٍ قائمة» بل: **الدَّورُ يُرتِّب، والحارسُ
| وحدَه يُظهِر.** فكلُّ عنوانٍ يمرُّ على `allowedNav` بعدَ الترتيب، وخطأٌ في تخمينِ
| الدَّورِ يُعيدُ الترتيبَ ولا يكشفُ بنداً واحداً. الحالةُ الأخيرةُ هنا هي التي
| تقيسُ ذلك، وهي الوحيدةُ التي تسقطُ لو صارَ الترتيبُ مصدرَ الإظهار.
*/

function person(over: Partial<User> = {}): User {
  return {
    uuid: "u-1",
    name: "خالد المنصوري",
    first_name: "خالد",
    email: "k@example.com",
    platform_role: "student",
    permissions: [],
    ...over,
  } as unknown as User;
}

describe("quickAccessFor", () => {
  it("gives a student their own four screens, timetable first", () => {
    const hrefs = quickAccessFor(person()).map((item) => item.href);

    expect(hrefs[0]).toBe("/schedule");
    expect(hrefs).toContain("/assignments");
    expect(hrefs).toContain("/exams");
    expect(hrefs).toContain("/leaderboard");
  });

  it("starts a guardian at the one screen that is theirs", () => {
    // وليُّ الأمرِ يقرأُ شاشاتِ ابنِه نفسَها — قرارُ المنتَجِ لا اختصارٌ هنا —
    // و«المرتبطون» هي الشاشةُ الوحيدةُ التي تخصُّه هو.
    const hrefs = quickAccessFor(person({ platform_role: "parent" })).map((item) => item.href);

    expect(hrefs[0]).toBe("/family");
  });

  it("gives a teacher their teaching screens and NONE of the student ones", () => {
    const teacher = person({
      platform_role: "teacher",
      permissions: [P.sessionsManage, P.gradingPerform, P.coursesUpdate],
    } as Partial<User>);

    const hrefs = quickAccessFor(teacher).map((item) => item.href);

    expect(hrefs).toContain("/manage/sessions");
    expect(hrefs).toContain("/manage/grading");
    // ⚠️ ولا «واجباتي» ولا «لوحة الصدارة»: كلاهما `audience: "learner"`، وهو ما
    // كانَ يعرضُهما لكلِّ من ليسَ طالباً قبلَ هذا التغيير — قائمةٌ واحدةٌ للجميع.
    expect(hrefs).not.toContain("/assignments");
    expect(hrefs).not.toContain("/leaderboard");
  });

  it("gives a finance officer only what they hold, from the same staff list", () => {
    // لا قائمةَ ثالثةٌ للمشرِف: الصلاحيّاتُ تفرزُ داخلَ القائمةِ نفسِها.
    const officer = person({ platform_role: null, permissions: [P.billingCollection] } as Partial<User>);

    const hrefs = quickAccessFor(officer).map((item) => item.href);

    expect(hrefs).toEqual(["/manage/payments/collection"]);
  });

  it("ORDERS by role and never reveals by it", () => {
    // ⚠️ التوكيدُ الحاسم. الطالبُ يُرتَّبُ بقائمةِ المتعلّم، لكنّ ما يظهرُ له يمرُّ
    // على الحارسِ نفسِه — فلو صارَ الترتيبُ مصدرَ الإظهارِ يوماً، هذه وحدَها تسقط.
    const student = person();
    const visible = allowedNav(mainNav, student).map((item) => item.href);

    for (const href of quickAccessFor(student).map((item) => item.href)) {
      expect(visible).toContain(href);
    }
  });

  it("stays a shortcut rather than a second sidebar", () => {
    expect(quickAccessFor(person()).length).toBeLessThanOrEqual(5);
  });

  it("answers nothing for a guest, who has no account to shortcut into", () => {
    expect(quickAccessFor(null)).toEqual([]);
  });
});
