import { readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import {
  adminNav,
  allowedNav,
  DEFAULT_PAGE_TITLE,
  mainNav,
  navLabel,
  pageTitleFor,
  platformNav,
  quickAccessFor,
} from "./panel-nav";
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
      "/mistakes",
      "/practice",
      "/assignments",
      "/exams",
      "/schedule",
      "/shop",
      "/plans",
    ]) {
      expect(seen).not.toContain(href);
    }
  });

  /*
  | ⚠️ `/orders` و`/billing` خرجَتا من القائمةِ أعلاه في ٠٣٠، وهو تصحيحُ ارتدادٍ
  | لا توسيعُ نطاق.
  |
  | المرحلةُ ٠٢٩ أعطَت وليَّ الأمرِ شراءً لابنِه: `‎/subscribe` ترسلُ
  | `student_uuid`، ثمّ تقولُ له في لافتةِ نجاحٍ «افتح صفحة الطلبات» — وتلك اللافتةُ
  | كانت طريقَه **الوحيد**. يغادرُ الصفحةَ فيضيعُ الطلبُ الذي دفعَ ثمنَه، و`‎/orders`
  | هو السطحُ الوحيدُ في المنتَجِ لاستبدالِ إيصالٍ مرفوض. و`‎/billing` تحملُ بطاقةَ
  | الموافقةِ على الشروط.
  |
  | ما تحرسُه القائمةُ أعلاه لم يتغيّر: شاشاتُ الطالبِ التي لا معنى لها لوليِّ أمرٍ
  | (تعلّمُه هو، شهاداتُه هو، أوراقُه هو) تبقى محجوبة.
  */
  it("gives a guardian the two screens their own purchase produced", () => {
    const seen = hrefs(person({ platform_role: "parent" }));

    expect(seen).toContain("/orders");
    expect(seen).toContain("/billing");
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

/*
| ⛔ **صلاحيّةٌ لا تكفي — لينكٌ يوصِّلُ إلى رفضٍ دائم.**
|
| `settlement.statement.view` صلاحيّةٌ يأخذُها مالكُ المنصّةِ تلقائيّاً عبرَ
| `Gate::before`، فظهرَ له «كشف التسوية» — والخادمُ يبني الكشفَ من
| `teacher_profiles` وهو لا صفَّ له فيها (مقيسٌ على الإنتاج ٢٠٢٦-٠٩-١٦: صفرٌ
| له من أصلِ أربعة). فتحَه وقرأ «العنصر المطلوب غير موجود أو حُذف».
|
| **وشقّانِ ضدّان**: يُخفى عمّن لا ملفَّ له، ويبقى لمن له — وشقٌّ واحدٌ يمرُّ
| على بناءٍ يُخفيه عن كلِّ أحدٍ بمن فيهم المدرّس.
*/
describe("a screen that needs a teaching profile, not just a permission", () => {
  const withPermission = { permissions: [P.settlementStatement] };

  it("hides «كشف التسوية» from a reader who holds no teaching profile", () => {
    const hrefs = allowedNav(
      mainNav,
      person({ ...withPermission, teacher_profile_uuid: null }),
    ).map((item) => item.href);

    expect(hrefs).not.toContain("/manage/settlement");
  });

  it("keeps it for the teacher it belongs to", () => {
    const hrefs = allowedNav(
      mainNav,
      person({ ...withPermission, teacher_profile_uuid: "tp-1" }),
    ).map((item) => item.href);

    expect(hrefs).toContain("/manage/settlement");
  });
});

/*
| شرطٌ ثانٍ، وهو شرطُ شخصٍ آخر (طلبُ ٢٠٢٦-٠٩-١٦).
|
| «أرصدة الطلاب» تردُّ ٤٠٣ بلا مساحةِ عملٍ من `abort_if($workspaceId === null)`
| — لا من الصلاحيّة. ومالكُ المنصّةِ يمرُّ فوقَ كلِّ صلاحيّةٍ بـ`Gate::before`
| ولا يملكُ مساحةَ عملٍ واحدة، فاللينكُ كانَ يُعرَضُ له ويوصِّلُ إلى رفضٍ دائم.
|
| **وشقّانِ ضدّان** كالزوجِ أعلاه، **والثالثُ يمنعُ الخلطَ بينَ الشرطَين**: مساعدُ
| مدرّسٍ لا ملفَّ تدريسٍ له ويملكُ مساحةَ عمل، فتوحيدُ الشرطَينِ في واحدٍ كانَ
| سيسحبُ منه شاشةً هي شاشتُه.
*/
describe("a screen that needs a workspace, not just a permission", () => {
  const withPermission = { permissions: [P.billingBalanceView] };

  it("hides «أرصدة الطلاب» from a platform owner who holds no workspace", () => {
    const hrefs = allowedNav(adminNav, person({ ...withPermission, workspaces: [] })).map(
      (item) => item.href,
    );

    expect(hrefs).not.toContain("/manage/billing/students");
  });

  it("keeps it for the teacher whose workspace it is about", () => {
    const hrefs = allowedNav(
      adminNav,
      person({ ...withPermission, workspaces: [{ uuid: "w-1", name: "أكاديميتي" }] }),
    ).map((item) => item.href);

    expect(hrefs).toContain("/manage/billing/students");
  });

  it("does not confuse the two preconditions — an assistant has a workspace and no profile", () => {
    const hrefs = allowedNav(
      adminNav,
      person({
        ...withPermission,
        teacher_profile_uuid: null,
        workspaces: [{ uuid: "w-1", name: "أكاديميتي" }],
      }),
    ).map((item) => item.href);

    expect(hrefs).toContain("/manage/billing/students");
    // والشاشةُ الأخرى — في قائمةٍ أخرى — تبقى مخفيّةً عنه بالشرطِ الآخر.
    expect(allowedNav(mainNav, person({
      permissions: [P.settlementStatement],
      teacher_profile_uuid: null,
      workspaces: [{ uuid: "w-1", name: "أكاديميتي" }],
    })).map((item) => item.href)).not.toContain("/manage/settlement");
  });
});

/*
| لوحةُ المنصّةِ مبنيّةٌ منذُ زمنٍ ولم يكنْ إليها طريقٌ من المنتَج (طلبُ ٢٠٢٦-٠٩-١٦).
|
| ⚠️ **والشرطُ `may_access_admin_panel` لا `is_super_admin`.** الحالةُ الثالثةُ
| هي التي تحرسُ ذلك: موظّفُ المنصّةِ — مسؤولُ الماليّةِ مثلاً — لا يحملُ الثاني،
| و`‎/admin` شاشاتُه **الوحيدة**. فاشتقاقٌ من عَلَمِ السوبر أدمن كانَ سيُخفيها
| عمَّن لا شاشةَ له سواها، وهو عطبٌ لا يُرى إلّا بحسابٍ من هذا النوع.
*/
describe("the platform panel is reachable from the product", () => {
  const hrefs = (over: Partial<User>) =>
    allowedNav(platformNav, person(over)).map((item) => item.href);

  it("offers «لوحة المنصّة» to whoever the server says may enter it", () => {
    expect(hrefs({ may_access_admin_panel: true })).toContain("/admin");
  });

  it("offers it to nobody else", () => {
    expect(hrefs({ may_access_admin_panel: false })).not.toContain("/admin");
    expect(hrefs({})).not.toContain("/admin");
  });

  it("reads the server's answer, never the super-admin flag", () => {
    // موظّفُ منصّةٍ: يدخُلُ اللوحةَ ولا يحملُ `is_super_admin`.
    expect(hrefs({ may_access_admin_panel: true, is_super_admin: false })).toContain("/admin");
    // ولا يُعرَضُ لمن يحملُ العَلَمَ وحدَه بلا جوابِ الخادم — الحقلُ هو الحَكَم.
    expect(hrefs({ is_super_admin: true })).not.toContain("/admin");
  });

  it("leaves the panel as an external address, not a Next route", () => {
    const item = platformNav.find((entry) => entry.href === "/admin");

    expect(item?.external).toBe(true);
  });
});

/*
| The top bar's title — measured on production 2026-09-24: «لوحة التحكم» over a
| group's page and a session room, and «لوحة التصحيح» over «أوزان التقدير»,
| because `/manage/grading-schemes` starts with `/manage/grading`.
*/
describe("pageTitleFor", () => {
  it.each([
    ["/manage/grading-schemes", "أوزان التقدير"],
    ["/manage/grading", "لوحة التصحيح"],
    ["/manage/grading/abc", "لوحة التصحيح"],
    ["/manage/cohorts/abc", "المجموعة"],
    ["/sessions/abc", "الحصّة"],
    ["/sessions/abc/room", "غرفة الحصّة"],
    ["/settings/privacy", "خصوصيّتي"],
    ["/dashboard", "لوحة التحكم"],
  ])("%s → %s", (path, title) => {
    expect(pageTitleFor(path)).toBe(title);
  });

  it("gives every screen in the panel a title of its own", () => {
    // A new screen with no menu entry and no detail title falls back to «لوحة
    // التحكم», naming a page the reader is not on. Walk every page file so the
    // next one is named here rather than on production.
    const shell = join(process.cwd(), "src", "app", "(app)", "(shell)");
    const routes: string[] = [];

    const walk = (dir: string, prefix: string) => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
          const segment = /^\(.*\)$/.test(entry.name)
            ? ""
            : `/${entry.name.replace(/^\[.*\]$/, "sample")}`;
          walk(join(dir, entry.name), prefix + segment);
        } else if (entry.name === "page.tsx") {
          routes.push(prefix === "" ? "/" : prefix);
        }
      }
    };

    walk(shell, "");

    const untitled = routes.filter(
      (route) => route !== "/dashboard" && pageTitleFor(route) === DEFAULT_PAGE_TITLE,
    );

    expect(routes.length).toBeGreaterThan(50);
    expect(untitled).toEqual([]);
  });
});
