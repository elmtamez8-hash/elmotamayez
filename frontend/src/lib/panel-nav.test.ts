import { describe, expect, it } from "vitest";

import { adminNav, allowedNav, mainNav, navLabel, quickAccessFor } from "./panel-nav";
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

/*
| ٢٠٢٦-٠٩-٠٧ — بلاغٌ من حسابِ وليِّ أمرٍ حقيقيّ: «في صفحات ظاهرة المفروض ما
| تظهرلوش».
|
| ⚠️ **واتّجاهُ المنعِ هو الفارِق.** `isLearner()` تُجيبُ بنعم عن وليِّ الأمر، فكانت
| خمسَ عشْرةَ شاشةً من شاشاتِ الطالبِ في شريطِه الجانبيّ — وكلُّ توكيدةٍ تسألُ
| «هل يرى كذا؟» كانت تمرُّ خضراءَ فوقَ ذلك بالضبط. الحالاتُ هنا تسألُ ما لا يجبُ
| أن يراه.
*/
describe("allowedNav · جمهورُ الشاشة", () => {
  const hrefs = (u: User) => allowedNav([...mainNav, ...adminNav], u).map((i) => i.href);

  it("keeps the student's own screens out of a guardian's sidebar", () => {
    const seen = hrefs(person({ platform_role: "parent" }));

    for (const href of [
      "/enrollments",
      "/certificates",
      "/billing",
      "/mistakes",
      "/practice",
      "/assignments",
      "/exams",
      "/orders",
      "/schedule",
      "/shop",
      "/plans",
    ]) {
      expect(seen).not.toContain(href);
    }
  });

  it("keeps the two screens a guardian really reads, and the one that is theirs", () => {
    // ⚠️ النصفُ الثاني: منعٌ يبتلعُ الشاشتَينِ اللتَينِ فيهما منتقي ابنٍ مكتوبٌ
    // فعلاً هو إصلاحٌ يكسِرُ نصفَ ما جاءَ يحرسُه.
    const seen = hrefs(person({ platform_role: "parent" }));

    expect(seen).toContain("/report-cards");
    expect(seen).toContain("/reviews");
    expect(seen).toContain("/family");
    expect(seen).toContain("/messages");
  });

  it("leaves the student everything that is theirs", () => {
    const seen = hrefs(person());

    expect(seen).toContain("/enrollments");
    expect(seen).toContain("/certificates");
    expect(seen).toContain("/report-cards");
    expect(seen).toContain("/schedule");
  });

  it("shows neither side's learning screens to a teacher", () => {
    const seen = hrefs(person({ platform_role: "teacher", permissions: [P.sessionsManage] } as Partial<User>));

    expect(seen).not.toContain("/enrollments");
    expect(seen).not.toContain("/report-cards");
    expect(seen).toContain("/manage/sessions");
  });
});

describe("navLabel · اسمُ الشاشةِ عندَ قارئِها", () => {
  it("does not call a guardian's child's assessments «mine»", () => {
    // ⚠️ الصفحةُ كانت تعرفُ هذا منذُ ٠١٠ والشريطُ لا. الاسمانِ في مكانٍ واحدٍ
    // الآن، وهذه الحالةُ هي التي تسقطُ لو عادَ أحدُهما يُكتَبُ بجوارِ الآخر.
    expect(navLabel("/reviews", person({ platform_role: "parent" }))).toBe("التقييمات الدورية");
    expect(navLabel("/reviews", person())).toBe("تقييماتي الدورية");
  });

  it("answers nothing for a screen this reader may not see", () => {
    expect(navLabel("/reviews", person({ platform_role: "teacher" }))).toBeUndefined();
  });
});
