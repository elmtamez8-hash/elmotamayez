import { describe, expect, it } from "vitest";

import { dashboardAudience } from "./dashboard-audience";
import { isLearner } from "./auth-context";
import { P } from "./permissions";
import type { User } from "./types";

/*
| ٠٢٩ · `FR-001` — من يفتحُ اللوحة.
|
| ⚠️ الحالةُ الحاملةُ هي **الأخيرة**: وليُّ الأمرِ متعلّمٌ عندَ `isLearner()`
| وغيرُ طالبٍ هنا. فلو صارَت هذه الدالّةُ تهجئةً ثانيةً لتلك، لسقطَت وحدَها —
| وبقيَت الأربعُ فوقَها خضراءَ عن شاشةٍ تخاطبُ وليَّ الأمرِ بصيغةِ الطالب.
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

describe("dashboardAudience", () => {
  it("reads the three declared roles", () => {
    expect(dashboardAudience(person({ platform_role: "student" }))).toBe("student");
    expect(dashboardAudience(person({ platform_role: "teacher" }))).toBe("teacher");
    expect(dashboardAudience(person({ platform_role: "parent" }))).toBe("guardian");
  });

  it("gives an academy founder the staff layout, since the role is null by design", () => {
    // `RegisterAccount`: «NULL IS THE CORRECT ROLE FOR AN ACADEMY FOUNDER» —
    // ومساحتُه تُولَدُ مع حسابِه (٠٢٥ · FR-001)، فهو يحملُ صلاحيّاتٍ من أوّلِ ثانية.
    const founder = person({ platform_role: null, permissions: [P.sessionsManage] });

    expect(dashboardAudience(founder)).toBe("teacher");
  });

  it("falls to the student layout for a null role holding nothing", () => {
    expect(dashboardAudience(person({ platform_role: null, permissions: [] }))).toBe("student");
    expect(dashboardAudience(null)).toBe("student");
  });

  it("gives a platform officer the staff layout with no rule of its own", () => {
    // ⚠️ لا سطرَ لـ`is_super_admin` هنا عمداً: `UserResource::grantedPermissions()`
    // يسألُ `$user->can()` لكلِّ صلاحيّة، و`Gate::before` يمرّرُ مديرَ المنصّةِ
    // فوقَ كلِّها — فحمولتُه تحملُ القائمةَ كاملةً. سطرٌ ثانٍ يُجيبُ السؤالَ نفسَه
    // هو التهجئةُ الثانيةُ التي يدفعُ ثمنَها هذا المستودعُ في كلِّ مرّة.
    const officer = person({
      platform_role: null,
      is_super_admin: true,
      permissions: [P.sessionsManage, P.membersView],
    });

    expect(dashboardAudience(officer)).toBe("teacher");
  });

  it("separates a guardian from a student where isLearner deliberately does not", () => {
    const guardian = person({ platform_role: "parent" });

    expect(isLearner(guardian)).toBe(true);
    expect(dashboardAudience(guardian)).not.toBe("student");
  });
});
