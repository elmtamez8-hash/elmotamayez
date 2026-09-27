import { render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { User } from "@/lib/types";

import manifest from "../manifest";
import { StartRedirect } from "./StartRedirect";

/*
| The installed app opens `/start`, which decides (owner decision 2026-09-27):
| a session goes where SIGNING IN would send that person (`homePathFor`, the
| login page's own rule — real here, not mocked), a visitor to the public home.
*/

const replace = vi.fn();
let auth: { user: User | null; loading: boolean } = { user: null, loading: false };

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
}));

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");
  return { ...actual, useAuth: () => auth };
});

vi.mock("@/lib/platform", () => ({ platformName: async () => "المنصّة" }));

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

beforeEach(() => {
  replace.mockReset();
});

describe("StartRedirect", () => {
  it.each([
    ["a student", user({ platform_role: "student" }), "/teachers"],
    ["a guardian", user({ platform_role: "parent" }), "/teachers"],
    ["a teacher", user({ workspaces: teaching }), "/dashboard"],
    ["platform staff", user({ is_super_admin: true }), "/dashboard"],
  ])("sends %s where signing in would", (_who, who, path) => {
    auth = { user: who, loading: false };

    render(<StartRedirect />);

    expect(replace).toHaveBeenCalledWith(path);
  });

  it("opens the public home for a visitor", () => {
    auth = { user: null, loading: false };

    render(<StartRedirect />);

    expect(replace).toHaveBeenCalledWith("/");
  });

  it("decides nothing while the session is still being read", () => {
    auth = { user: null, loading: true };

    render(<StartRedirect />);

    expect(replace).not.toHaveBeenCalled();
  });

  it("is where the installed app starts", async () => {
    // The route above is only reachable because the manifest names it.
    expect((await manifest()).start_url).toBe("/start");
  });
});
