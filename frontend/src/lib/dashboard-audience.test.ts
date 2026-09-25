import { describe, expect, it } from "vitest";

import { dashboardAudience } from "./dashboard-audience";
import { homePathFor, isLearner, panelPathFor } from "./auth-context";
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
    // ⚠️ والمساحةُ في الحمولةِ لا الصلاحيّاتُ وحدَها: `workspaces` هي ما يقرؤه
    // المُصنِّف، وهي ما يحملُه المؤسّسُ فعلاً.
    const founder = person({
      platform_role: null,
      permissions: [P.sessionsManage],
      workspaces: [{ uuid: "w-1", name: "أكاديمية النور" }],
    });

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

/*
| ⛔ ONE CLASSIFIER, EVERY READER. `isLearner`, `homePathFor` and `panelPathFor`
| are derived from `dashboardAudience`, so this table walks the account shapes
| the server actually sends and asserts that none of them can disagree.
*/
describe("one classifier for «which side is this account»", () => {
  const teaching = [{ uuid: "w-1", name: "أكاديمية" }];

  function payload(over: Partial<User>): User {
    return {
      platform_role: null,
      is_super_admin: false,
      may_access_admin_panel: false,
      workspaces: [],
      permissions: [],
      ...over,
    } as User;
  }

  const table: { name: string; user: User; side: "student" | "teacher" | "guardian" }[] = [
    { name: "a student", user: payload({ platform_role: "student" }), side: "student" },
    { name: "a guardian", user: payload({ platform_role: "parent" }), side: "guardian" },
    {
      name: "a guardian who also teaches",
      user: payload({ platform_role: "parent", workspaces: teaching }),
      side: "guardian",
    },
    {
      name: "a teacher",
      user: payload({ platform_role: "teacher", workspaces: teaching }),
      side: "teacher",
    },
    {
      name: "a founder (null role, owns a workspace)",
      user: payload({ workspaces: teaching, permissions: [P.sessionsManage] }),
      side: "teacher",
    },
    {
      name: "a super admin with no workspace",
      user: payload({ is_super_admin: true, permissions: [P.sessionsManage] }),
      side: "teacher",
    },
    { name: "a platform officer", user: payload({ may_access_admin_panel: true }), side: "teacher" },
    {
      // ⚠️ THE CASE THAT CHANGED: a student a teacher added to a workspace
      // carries the `student` role's permissions and no `workspaces` entry (the
      // server excludes the `student` pivot role). It used to get the teacher
      // layout here while `panelPathFor` sent it to `/enrollments`.
      name: "a null-role student holding the student role's permissions",
      // (The student role's names are absent from `P` — no screen gates on them.)
      user: payload({ permissions: ["courses.view", "sessions.view", "orders.create"] }),
      side: "student",
    },
    { name: "a null-role account holding nothing", user: payload({}), side: "student" },
    {
      // Teaching is asked BEFORE the `student` role — the order `homePathFor`
      // already had. The server never sends this shape today
      // (`UserResource::workplaces()` returns [] for a `student` role), but the
      // sidebar and the landing path used to answer it differently.
      name: "a student-role account that teaches",
      user: payload({ platform_role: "student", workspaces: teaching }),
      side: "teacher",
    },
  ];

  it.each(table)("classifies $name", ({ user, side }) => {
    expect(dashboardAudience(user)).toBe(side);
  });

  it.each(table)("never lets the landing paths or isLearner disagree for $name", ({ user, side }) => {
    const learns = side !== "teacher";

    expect(isLearner(user)).toBe(learns);
    expect(homePathFor(user)).toBe(learns ? "/teachers" : "/dashboard");
    expect(panelPathFor(user)).toBe(learns ? "/enrollments" : "/dashboard");
  });

  it("treats no user as a visitor: not a learner, student layout", () => {
    expect(isLearner(null)).toBe(false);
    expect(dashboardAudience(null)).toBe("student");
  });
});
