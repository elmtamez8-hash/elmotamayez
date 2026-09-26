import { act, render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PublicProfileUrlCard } from "./PublicProfileUrlCard";
import type { User } from "@/lib/types";

/*
| ⛔ بلاغُ ٢٠٢٦-٠٩-٢٤: صفحةُ «الإعدادات» عندَ الطالبِ كانت تطلبُ
| `GET /teacher/profile` وتتلقّى ٤٠٣ في كلِّ زيارة. البطاقةُ لا تُرسَمُ له
| على أيِّ حال — فالسؤالُ نفسُه هو الخلل، لا ما يُعرَضُ بعده.
|
| ⚠️ والتوكيدُ على قائمةِ الطلبات، لا على غيابِ البطاقة: البطاقةُ كانت غائبةً
| قبلَ الإصلاحِ أيضاً، فتوكيدٌ على الشاشةِ يمرُّ على الخللِ كاملاً.
*/

let mockUser: Partial<User> | null = null;
let mockLoading = false;
const get = vi.fn();

vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: mockUser, loading: mockLoading }),
}));

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path), put: vi.fn() },
}));

async function mount() {
  await act(async () => {
    render(<PublicProfileUrlCard />);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = null;
  mockLoading = false;
  get.mockResolvedValue({
    slug: "sami",
    is_publicly_listed: true,
    workspace_participates_in_marketplace: true,
  });
});

describe("PublicProfileUrlCard", () => {
  it("never asks for a teacher profile on a student's settings page", async () => {
    mockUser = { uuid: "u-1", platform_role: "student" };

    await mount();

    expect(get).not.toHaveBeenCalled();
  });

  it("never asks on a guardian's settings page either", async () => {
    mockUser = { uuid: "u-2", platform_role: "parent" };

    await mount();

    expect(get).not.toHaveBeenCalled();
  });

  it("asks nothing while the session is still being restored", async () => {
    mockLoading = true;

    await mount();

    expect(get).not.toHaveBeenCalled();
  });

  it("still asks for a teacher", async () => {
    // The guard in the other direction: an inverted condition hides every card.
    mockUser = {
      uuid: "t-1",
      platform_role: "teacher",
      workspaces: [{ uuid: "w-1", name: "أكاديمية" }],
      teacher_profile_uuid: "p-1",
    };

    await mount();

    expect(get).toHaveBeenCalledWith("/teacher/profile");
  });

  it("asks for an academy account whose role says nothing, because it teaches", async () => {
    mockUser = {
      uuid: "t-2",
      platform_role: null,
      workspaces: [{ uuid: "w-2", name: "أكاديمية" }],
      teacher_profile_uuid: "p-2",
    };

    await mount();

    expect(get).toHaveBeenCalledWith("/teacher/profile");
  });

  it("never asks for a workspace owner who has no teacher profile", async () => {
    // ⛔ 2026-09-26: teaching by the pivot role is not HAVING a profile — this
    // account took a 403 on every visit to its own settings.
    mockUser = {
      uuid: "o-1",
      platform_role: null,
      workspaces: [{ uuid: "w-3", name: "أكاديمية" }],
      teacher_profile_uuid: null,
    };

    await mount();

    expect(get).not.toHaveBeenCalled();
  });

  it("never asks for a role-less student who teaches nowhere", async () => {
    // ⚠️ `platform_role` is null for students a teacher or a seeder created, and
    // «not a learner» asked on their behalf. Teaching is what decides.
    mockUser = { uuid: "s-3", platform_role: null, workspaces: [] };

    await mount();

    expect(get).not.toHaveBeenCalled();
  });
});
