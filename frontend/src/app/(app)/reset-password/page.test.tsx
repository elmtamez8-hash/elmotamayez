import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import ResetPasswordPage from "./page";

/*
| The page the reset mail opens. Before 2026-09-23 there was no such page and no
| «نسيت كلمة المرور؟» link anywhere, so a student who forgot their password had
| no way back into a course they had paid for.
*/

const searchParams = new URLSearchParams();
const push = vi.fn();
const resetPassword = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  useSearchParams: () => searchParams,
}));

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  auth: { resetPassword: (...args: unknown[]) => resetPassword(...args) },
}));

afterEach(() => {
  cleanup();
  push.mockReset();
  resetPassword.mockReset();
});

describe("ResetPasswordPage", () => {
  it("says the link is incomplete when it carries no token", () => {
    searchParams.delete("token");
    searchParams.delete("email");

    render(<ResetPasswordPage />);

    expect(screen.getByText("الرابط غير مكتمل")).toBeTruthy();
  });

  it("sends the token from the link and returns to sign-in with the address filled", async () => {
    searchParams.set("token", "tok-1");
    searchParams.set("email", "amal@example.test");
    resetPassword.mockResolvedValue({ message: "ok" });

    render(<ResetPasswordPage />);

    fireEvent.change(screen.getByLabelText(/كلمة المرور الجديدة/), { target: { value: "N3w-passw0rd!" } });
    fireEvent.change(screen.getByLabelText(/تأكيد كلمة المرور/), { target: { value: "N3w-passw0rd!" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ كلمة المرور" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/login?email=amal%40example.test"));
    expect(resetPassword).toHaveBeenCalledWith({
      email: "amal@example.test",
      token: "tok-1",
      password: "N3w-passw0rd!",
      password_confirmation: "N3w-passw0rd!",
    });
  });
});
