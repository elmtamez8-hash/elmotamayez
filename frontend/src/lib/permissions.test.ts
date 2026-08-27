import { describe, expect, it } from "vitest";

import { refusedBy } from "./permissions";

/*
| قفلُ العنوان: الشريطُ الجانبيُّ كان يُخفي الروابطَ وشريطُ العنوانِ لا يُخفي شيئاً.
|
| كلُّ شاشةِ `/manage/*` كانت تُرسَمُ كاملةً لطالبٍ مسجَّلٍ يكتبُ عنوانَها — تقويمُ
| المدرّسِ بزرِّ «دخول الغرفة»، ولوحةُ التصحيح، وفريقُ المساعدين. لا شيءَ يتسرَّب،
| لأنّ الخادمَ يرفضُ كلَّ قراءة؛ لكنّ ما وصلَ الطالبَ كان «تعذّر تحميل البيانات —
| تحقّق من اتصالك»: رفضُ ٤٠٣ بثوبِ عطلٍ في الشبكة، تحتَ عنوانٍ عن أرصدةِ طلابه.
|
| ⚠️ والخريطةُ تُمرَّرُ ولا تُنسَخ: الصلاحيةُ التي تُخفي الرابطَ والصلاحيةُ التي تُغلِقُ
| الشاشةَ خلفَه جوابٌ واحد. وقائمةٌ ثانيةٌ بجانبِ هذه تشيخُ عندَ أوّلِ مدخلٍ يُضاف،
| وتشيخُ في الاتّجاهِ المفتوح.
*/

const NAV = [
  { href: "/dashboard", label: "لوحة التحكم" },
  { href: "/schedule", label: "جدولي" },
  { href: "/manage/sessions", permission: "sessions.manage" },
  { href: "/manage/billing/students", permission: "billing.balance.view" },
  { href: "/manage/billing/exam-mode", permission: "billing.exam-mode.manage" },
  { href: "/teaching/offboarding", permission: "settlement.statement.view" },
  { href: "/workspaces", permission: "members.view", linkOnly: true },
];

const student = { permissions: ["sessions.view", "orders.view.own"] };
const teacher = {
  permissions: ["sessions.manage", "billing.balance.view", "settlement.statement.view"],
};

describe("refusedBy", () => {
  it("closes a teacher screen a student typed the address of", () => {
    expect(refusedBy(NAV, "/manage/sessions", student)).toBe(true);
    expect(refusedBy(NAV, "/teaching/offboarding", student)).toBe(true);
  });

  it("leaves the same screen open for the reader it belongs to", () => {
    // The positive control: a gate that refuses everybody passes the assertion
    // above and locks the teacher out of their own calendar.
    expect(refusedBy(NAV, "/manage/sessions", teacher)).toBe(false);
    expect(refusedBy(NAV, "/teaching/offboarding", teacher)).toBe(false);
  });

  it("covers the pages under a gated entry, not just the entry itself", () => {
    // `/manage/sessions/{uuid}/attendance` is the class register. An exact-match
    // gate would refuse the parent and hand over every child.
    expect(refusedBy(NAV, "/manage/sessions/abc-123/attendance", student)).toBe(true);
  });

  it("judges by the longest matching entry", () => {
    /*
     | ⚠️ `/manage/billing/students` AND `/manage/billing/exam-mode` ARE TWO
     | PERMISSIONS UNDER ONE PREFIX. First-match-wins on an array whose order is
     | whatever the sidebar reads best would judge one screen by the other's
     | permission — in whichever direction the entries happen to sit.
     */
    const partial = { permissions: ["billing.balance.view"] };

    expect(refusedBy(NAV, "/manage/billing/students", partial)).toBe(false);
    expect(refusedBy(NAV, "/manage/billing/exam-mode", partial)).toBe(true);
  });

  it("leaves a path no entry covers alone", () => {
    // Every student screen is here, and so is every deep link that is nobody's
    // menu item — the broadcast room most of all. A gate that defaulted to
    // closed would lock a paying student out of the lesson they booked.
    expect(refusedBy(NAV, "/sessions/abc-123/room", student)).toBe(false);
    expect(refusedBy(NAV, "/schedule", student)).toBe(false);
    expect(refusedBy(NAV, "/learn/lesson-9", student)).toBe(false);
  });

  it("refuses a signed-out reader rather than throwing", () => {
    expect(refusedBy(NAV, "/manage/sessions", null)).toBe(true);
  });

  it("leaves a linkOnly route open to the reader its link is hidden from", () => {
    /*
     | ⚠️ `/workspaces/new` IS HOW A PERSON WITH NO WORKSPACE MAKES THEIR FIRST,
     | and holding no workspace means holding no workspace permission. Gating the
     | route the way every other entry is gated would lock out precisely the
     | reader it exists for — which is why `POST /workspaces` is ungated on the
     | server too. The permission hides the menu item and nothing else.
     */
    expect(refusedBy(NAV, "/workspaces", student)).toBe(false);
    expect(refusedBy(NAV, "/workspaces/new", student)).toBe(false);
  });
});
