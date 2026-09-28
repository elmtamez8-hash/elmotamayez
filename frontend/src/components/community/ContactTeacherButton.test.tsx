import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ContactTeacherButton } from "./ContactTeacherButton";
import type { ContactOption } from "@/lib/conversations";

/*
 * «تواصل مع المدرّس» on the public pages (owner decisions 2026-09-28).
 *
 * The button decides nothing about who may write — it reads
 * `GET /conversations/contact-options`, which the server derives from the
 * conversation door's own predicate. What only a component test can see is that
 * each reader gets the RIGHT control: a visitor a sign-in link that brings them
 * back, a teacher nothing, a student the thread or the compose view, a guardian
 * a choice of child, and a refusal a calm sentence instead of a button.
 */
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const auth = vi.hoisted(() => ({
  user: null as null | { workspaces?: { uuid: string }[]; platform_role?: string | null },
  loading: false,
}));

vi.mock("@/lib/api", () => ({
  api,
  fieldErrors: () => ({}),
  ApiError: class ApiError extends Error {},
}));
vi.mock("@/lib/auth-context", async () => ({
  useAuth: () => auth,
  teachesOnPlatform: (user: { workspaces?: unknown[] } | null) => (user?.workspaces?.length ?? 0) > 0,
}));
vi.mock("next/navigation", () => ({ usePathname: () => "/courses/algebra" }));

function option(overrides: Partial<ContactOption> = {}): ContactOption {
  return {
    student_uuid: "s-1",
    student_name: null,
    conversation_uuid: null,
    can_start: true,
    reason: null,
    is_subscriber: false,
    remaining: 3,
    ...overrides,
  };
}

function answers(options: ContactOption[], note: string | null = null) {
  api.get.mockResolvedValue({ data: { options, note } });
}

function renderButton() {
  return render(<ContactTeacherButton workspaceUuid="w-1" contactName="سارة (Nour Academy)" />);
}

describe("ContactTeacherButton", () => {
  beforeEach(() => {
    api.get.mockReset();
    api.post.mockReset();
    auth.user = null;
    auth.loading = false;
  });

  it("sends a visitor to sign in and brings them back to this page", () => {
    renderButton();

    const link = screen.getByRole("link", { name: /سجّل الدخول لتتواصل مع سارة/ });

    expect(link.getAttribute("href")).toBe("/login?next=%2Fcourses%2Falgebra");
    expect(api.get).not.toHaveBeenCalled();
  });

  it("offers a teacher nothing, and asks the server nothing", () => {
    auth.user = { workspaces: [{ uuid: "w-9" }], platform_role: "teacher" };

    const { container } = renderButton();

    expect(container.textContent).toBe("");
    expect(api.get).not.toHaveBeenCalled();
  });

  it("takes a prospect student to the compose view with their allowance, never creating a thread", async () => {
    auth.user = { workspaces: [], platform_role: "student" };
    answers([option()]);

    renderButton();

    const link = await screen.findByRole("link", { name: /تواصل مع سارة/ });

    expect(link.getAttribute("href")).toBe(
      "/messages/new?workspace=w-1&name=%D8%B3%D8%A7%D8%B1%D8%A9+%28Nour+Academy%29&remaining=3",
    );
    expect(api.get).toHaveBeenCalledWith("/conversations/contact-options?workspace=w-1");
    expect(api.post).not.toHaveBeenCalled();
  });

  it("takes a student straight to the thread that already exists", async () => {
    auth.user = { workspaces: [], platform_role: "student" };
    answers([option({ conversation_uuid: "c-7", is_subscriber: true, remaining: null })]);

    renderButton();

    expect((await screen.findByRole("link", { name: /تواصل مع سارة/ })).getAttribute("href")).toBe("/messages/c-7");
  });

  it("shows a calm sentence instead of the button when the teacher takes no new messages", async () => {
    auth.user = { workspaces: [], platform_role: "student" };
    answers([option({ can_start: false, reason: "لا يستقبل هذا المدرّس رسائل جديدة من غير طلابه حالياً." })]);

    renderButton();

    expect(await screen.findByText("لا يستقبل هذا المدرّس رسائل جديدة من غير طلابه حالياً.")).toBeTruthy();
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("tells a guardian with no linked child why they cannot write", async () => {
    auth.user = { workspaces: [], platform_role: "parent" };
    answers([], "لا يوجد ابنٌ مرتبطٌ بحسابك يسمح لك بمراسلة مدرّسيه.");

    renderButton();

    expect(await screen.findByText(/لا يوجد ابنٌ مرتبطٌ بحسابك/)).toBeTruthy();
    expect(screen.queryByRole("link")).toBeNull();
  });

  it("writes as a guardian's only child without asking which", async () => {
    auth.user = { workspaces: [], platform_role: "parent" };
    answers([option({ student_uuid: "kid-1", student_name: "كريم" })]);

    renderButton();

    const link = await screen.findByRole("link", { name: /تواصل مع سارة/ });

    expect(link.getAttribute("href")).toContain("student=kid-1");
    expect(screen.queryByRole("combobox")).toBeNull();
  });

  it("asks a guardian of two children which one they are writing as", async () => {
    auth.user = { workspaces: [], platform_role: "parent" };
    answers([
      option({ student_uuid: "kid-1", student_name: "كريم" }),
      option({ student_uuid: "kid-2", student_name: "ليلى", conversation_uuid: "c-2", is_subscriber: true, remaining: null }),
    ]);

    renderButton();

    const picker = await screen.findByRole("combobox");

    // Nothing to press until a child is chosen.
    expect(screen.queryByRole("link")).toBeNull();

    await userEvent.selectOptions(picker, "kid-2");

    await waitFor(() =>
      expect(screen.getByRole("link", { name: /تواصل مع سارة/ }).getAttribute("href")).toBe("/messages/c-2"),
    );

    await userEvent.selectOptions(picker, "kid-1");

    await waitFor(() =>
      expect(screen.getByRole("link", { name: /تواصل مع سارة/ }).getAttribute("href")).toContain("student=kid-1"),
    );
  });
});
