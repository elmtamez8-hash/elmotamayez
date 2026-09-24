import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import RegisterPage from "./page";

/*
| `/register` بالدعوةِ وحدَها (2026-09-24).
|
| ⚠️ الحالتان تُقاسان معاً عمداً: تثبيتُ غيابِ النموذجِ بلا دعوةٍ وحدَه يمرُّ على
| بناءٍ أخفى النموذجَ عن الجميع — ومنهم المدعوُّ الذي لا بابَ له غيرُ هذه الصفحة.
*/

const searchParams = new URLSearchParams();
const push = vi.fn();
const register = vi.fn();
const login = vi.fn();


// The signed-in bounce has its own file (`SignedInRedirect.test.tsx`); here the
// form is what is measured, so the guard passes it straight through.
vi.mock("@/components/auth/SignedInRedirect", () => ({
  SignedInRedirect: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  useSearchParams: () => searchParams,
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ register, login }),
}));

beforeEach(() => {
  for (const key of [...searchParams.keys()]) searchParams.delete(key);
  push.mockReset();
  register.mockReset();
  login.mockReset();
});

afterEach(cleanup);

describe("RegisterPage", () => {
  it("shows no form without an invitation, and points to /signup", () => {
    render(<RegisterPage />);

    expect(screen.queryByLabelText(/البريد الإلكتروني/)).toBeNull();
    expect(screen.queryByRole("button", { name: "أنشئ الحساب" })).toBeNull();
    expect(
      screen.getByRole("link", { name: "اذهب إلى صفحة التسجيل" }).getAttribute("href"),
    ).toBe("/signup");
  });

  it("carries next across to /signup", () => {
    searchParams.set("next", "/courses/abc");

    render(<RegisterPage />);

    expect(
      screen.getByRole("link", { name: "اذهب إلى صفحة التسجيل" }).getAttribute("href"),
    ).toBe("/signup?next=%2Fcourses%2Fabc");
  });

  it("shows the form with an invitation and locks the invited email", () => {
    searchParams.set("invitation", "tok-1");
    searchParams.set("email", "jane@example.com");

    render(<RegisterPage />);

    const email = screen.getByLabelText(/البريد الإلكتروني/) as HTMLInputElement;

    expect(email.value).toBe("jane@example.com");
    expect(email.disabled).toBe(true);
    expect(screen.getByRole("button", { name: "أنشئ الحساب" })).toBeTruthy();
    expect(screen.queryByRole("link", { name: "اذهب إلى صفحة التسجيل" })).toBeNull();
  });

  it("leaves the email editable when the invitation link carried none", () => {
    searchParams.set("invitation", "tok-1");

    render(<RegisterPage />);

    expect((screen.getByLabelText(/البريد الإلكتروني/) as HTMLInputElement).disabled).toBe(false);
  });

  it("shows the server's refusal instead of a raw error", async () => {
    searchParams.set("invitation", "tok-1");
    searchParams.set("email", "jane@example.com");
    const refusal = "انتهت صلاحية هذه الدعوة. اطلب دعوة جديدة.";
    register.mockRejectedValue(new ApiError(refusal, 422, { message: refusal }));

    render(<RegisterPage />);

    fireEvent.change(screen.getByLabelText(/الاسم الأول/), { target: { value: "Jane" } });
    fireEvent.submit(screen.getByRole("button", { name: "أنشئ الحساب" }).closest("form")!);

    await waitFor(() => expect(screen.getByText("انتهت صلاحية هذه الدعوة. اطلب دعوة جديدة.")).toBeTruthy());
    expect(register).toHaveBeenCalledWith(expect.objectContaining({ invitation: "tok-1", email: "jane@example.com" }));
    expect(push).not.toHaveBeenCalled();
  });
});
