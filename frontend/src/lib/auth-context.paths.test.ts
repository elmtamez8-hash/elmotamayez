import { describe, expect, it } from "vitest";

import { homePathFor, panelPathFor } from "./auth-context";
import type { User } from "./types";

/*
| Where a signed-in person lands — decided by TEACHING, not by `platform_role`.
|
| ⚠️ The column is null for dozens of accounts, students a teacher or a seeder
| created among them, and «not a learner» sent each of those to `/dashboard`,
| which resolves a workspace they are not in. Guardians keep the learner side.
*/

function user(overrides: Partial<User>): User {
  return {
    platform_role: null,
    is_super_admin: false,
    may_access_admin_panel: false,
    workspaces: [],
    ...overrides,
  } as User;
}

const teaching = [{ uuid: "w-1", name: "أكاديمية" }];

describe("homePathFor / panelPathFor", () => {
  it("sends a student and a guardian to the learner side", () => {
    for (const role of ["student", "parent"] as const) {
      expect(homePathFor(user({ platform_role: role }))).toBe("/teachers");
      expect(panelPathFor(user({ platform_role: role }))).toBe("/enrollments");
    }
  });

  it("sends a role-less account that teaches nowhere to the learner side", () => {
    expect(homePathFor(user({}))).toBe("/teachers");
    expect(panelPathFor(user({}))).toBe("/enrollments");
  });

  it("sends anyone who teaches to the dashboard, whatever the role column says", () => {
    expect(homePathFor(user({ workspaces: teaching }))).toBe("/dashboard");
    expect(panelPathFor(user({ platform_role: "student", workspaces: teaching }))).toBe("/dashboard");
  });

  it("keeps a guardian on the learner side even when they also teach", () => {
    expect(homePathFor(user({ platform_role: "parent", workspaces: teaching }))).toBe("/teachers");
  });

  it("sends platform staff with no workspace to the dashboard", () => {
    expect(homePathFor(user({ is_super_admin: true }))).toBe("/dashboard");
    expect(panelPathFor(user({ may_access_admin_panel: true }))).toBe("/dashboard");
  });
});
