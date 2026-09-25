import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import MembersPage from "./page";

/**
 * ⛔ `members.update` NAMED A CAPABILITY THE PRODUCT DID NOT HAVE.
 *
 * It was declared, seeded, granted to the owner by the matrix and rendered on
 * the roles screen as «تعديل — الأعضاء» — with no PATCH, no relation manager and
 * no button anywhere. An owner who wanted to promote their assistant had one
 * road: remove them and invite them again, losing the membership record and
 * mailing them an invitation to a job they already held.
 *
 * ⚠️ THE TWO CASES THAT MATTER HERE ARE BOTH REFUSALS THE SERVER ALSO MAKES.
 * The owner's row may not change (nothing writes `owner_user_id`, so demoting
 * them leaves the person who owns the place holding a student's permissions
 * inside it), and a reader without the permission may not see the control at
 * all. Offering either is a control that answers 403 — which this repository has
 * already paid for once, as a sidebar full of links a student could not open.
 */
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
const auth = vi.hoisted(() => ({ user: { permissions: ["members.update"] } }));

/*
 * ⚠️ `ApiError` IS EXPORTED BY THE MOCK BECAUSE `lib/errors` IMPORTS IT.
 * `userMessage()` opens with `err instanceof ApiError`, so a mock without it
 * throws from inside the error handler — the one path this file is here to
 * check, failing with a message about the mock rather than about the page.
 */
vi.mock("@/lib/api", () => ({
  api,
  fieldErrors: () => ({}),
  ApiError: class ApiError extends Error {},
}));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));

const OWNER = {
  uuid: "u-owner",
  name: "هدى",
  email: "huda@example.com",
  role: "tenant-owner",
  role_label: "المالك",
  is_owner: true,
};

const ASSISTANT = {
  uuid: "u-asst",
  name: "سارة",
  email: "sara@example.com",
  role: "assistant-teacher",
  role_label: "مدرّس مساعد",
  is_owner: false,
};

function respond(members = [OWNER, ASSISTANT]) {
  api.get.mockImplementation((path: string) =>
    path === "/workspaces"
      ? Promise.resolve({ data: [{ uuid: "w-1", is_current: true }] })
      : Promise.resolve({ data: members }),
  );
}

describe("MembersPage", () => {
  beforeEach(() => {
    api.get.mockReset();
    api.patch.mockReset();
    api.delete.mockReset();
    auth.user = { permissions: ["members.update"] };
  });

  it("offers a role control for an ordinary member", async () => {
    respond();

    render(<MembersPage />);

    // Named after the person: a screen reader meets the control with no header
    // row for context, and "الدور" repeated down a table never says whose.
    expect(await screen.findByLabelText("دور سارة")).toBeTruthy();
  });

  it("does not offer one for the owner", async () => {
    respond();

    render(<MembersPage />);

    await screen.findByLabelText("دور سارة");

    // The server refuses this row with a 422; a control here would be a select
    // that always fails, which reads as a broken product rather than a rule.
    expect(screen.queryByLabelText("دور هدى")).toBeNull();
  });

  it("does not offer one to a reader without the permission", async () => {
    auth.user = { permissions: ["members.view"] };
    respond();

    render(<MembersPage />);

    // The list still renders — reading the team is a different question from
    // changing it, which is exactly why the two permissions are separate.
    expect(await screen.findByText("سارة")).toBeTruthy();
    expect(screen.queryByLabelText("دور سارة")).toBeNull();
  });

  it("sends the change and reloads from the server", async () => {
    respond();
    api.patch.mockResolvedValue(undefined);

    render(<MembersPage />);

    await userEvent.selectOptions(await screen.findByLabelText("دور سارة"), "teacher");

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith("/workspaces/w-1/members/u-asst", {
        role: "teacher",
      }),
    );

    /*
     * ⚠️ RELOADED, NEVER PATCHED IN PLACE. The role lives in two tables on the
     * other side — the membership row the list prints and spatie's team-scoped
     * roles every `can()` reads — and the server is the only thing that has seen
     * both. A local update would show a teacher over a member the server may
     * have refused.
     */
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(4));
  });

  it("never shows a raw error when the change is refused", async () => {
    respond();
    api.patch.mockRejectedValue(new Error("Request failed with status 422"));

    render(<MembersPage />);

    await userEvent.selectOptions(await screen.findByLabelText("دور سارة"), "student");

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 422/)).toBeNull();
  });

  /*
   * The server pages the list at fifty (the workspace's members include every
   * student ever added), so a second page must be REACHABLE and must ADD to
   * the list — a list that silently stops at fifty is a screen that lies.
   */
  it("fetches the next page on «عرض المزيد» and appends it", async () => {
    api.get.mockImplementation((path: string) => {
      if (path === "/workspaces") return Promise.resolve({ data: [{ uuid: "w-1", is_current: true }] });
      if (path.endsWith("?page=2")) return Promise.resolve({ data: [ASSISTANT], meta: { last_page: 2 } });
      return Promise.resolve({ data: [OWNER], meta: { last_page: 2 } });
    });

    render(<MembersPage />);

    await screen.findByText("هدى");
    expect(screen.queryByText("سارة")).toBeNull();

    await userEvent.click(screen.getByRole("button", { name: "عرض المزيد" }));

    expect(await screen.findByText("سارة")).toBeTruthy();
    expect(screen.getByText("هدى")).toBeTruthy();
    expect(api.get).toHaveBeenCalledWith("/workspaces/w-1/members?page=2");
    // The last page offers nothing further.
    expect(screen.queryByRole("button", { name: "عرض المزيد" })).toBeNull();
  });

  /*
   * ⛔ `DELETE …/members/{member}` HAD NO CALLER. The control is offered on
   * `members.remove` — the name the door asks since this change — never on the
   * owner's row, and it asks in a window before anything is sent.
   */
  describe("removal", () => {
    it("asks first, sends nothing until confirmed, then deletes that member and reloads", async () => {
      auth.user = { permissions: ["members.remove"] };
      respond();
      api.delete.mockResolvedValue(undefined);

      render(<MembersPage />);

      await userEvent.click(await screen.findByRole("button", { name: "إزالة سارة" }));

      // The question is on screen and nothing has left the browser yet.
      expect(await screen.findByText("إزالة سارة من فريقك؟")).toBeTruthy();
      expect(api.delete).not.toHaveBeenCalled();

      await userEvent.click(screen.getByRole("button", { name: "أزِله" }));

      await waitFor(() =>
        expect(api.delete).toHaveBeenCalledWith("/workspaces/w-1/members/u-asst"),
      );
      expect(api.delete).toHaveBeenCalledTimes(1);
      // Reloaded from the server: the initial pair of reads, then a second pair.
      await waitFor(() => expect(api.get).toHaveBeenCalledTimes(4));
    });

    it("never offers it on the owner's row, nor to a reader without members.remove", async () => {
      auth.user = { permissions: ["members.remove"] };
      respond();

      const { unmount } = render(<MembersPage />);

      await screen.findByRole("button", { name: "إزالة سارة" });
      expect(screen.queryByRole("button", { name: "إزالة هدى" })).toBeNull();
      unmount();

      // `members.update` and `members.invite` are different questions; neither
      // is the one the door asks before removing somebody.
      auth.user = { permissions: ["members.update", "members.invite"] };
      respond();
      render(<MembersPage />);

      await screen.findByText("سارة");
      expect(screen.queryByRole("button", { name: /إزالة/ })).toBeNull();
    });

    it("shows the server's refusal as a sentence, never raw", async () => {
      auth.user = { permissions: ["members.remove"] };
      respond();
      api.delete.mockRejectedValue(new Error("Request failed with status 403"));

      render(<MembersPage />);

      await userEvent.click(await screen.findByRole("button", { name: "إزالة سارة" }));
      await userEvent.click(await screen.findByRole("button", { name: "أزِله" }));

      expect(await screen.findByRole("alert")).toBeTruthy();
      expect(screen.queryByText(/status 403/)).toBeNull();
    });
  });
});
