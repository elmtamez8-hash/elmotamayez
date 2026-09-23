import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import SettingsPage from "./page";
import { ApiError } from "@/lib/api";

/*
| ⛔ A NEW ADDRESS COSTS THE CURRENT PASSWORD.
|
| The address is where the reset link goes, so the server refuses to move it on a
| bearer token alone. This screen is what asks for the password — only when the
| address actually changed, and its refusal lands under the field it is about.
|
| ⚠️ `fireEvent`, NOT `userEvent`, and the clock is faked: `userEvent` awaits real
| timers between its steps and hangs under a fake clock rather than failing.
*/

const patch = vi.fn();
const refreshUser = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    patch: (path: string, body: unknown) => patch(path, body),
    post: vi.fn(),
    get: vi.fn(() => Promise.reject(new Error("unused"))),
  },
}));

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return {
    ...actual,
    useAuth: () => ({
      user: { first_name: "هدى", last_name: "علي", email: "huda@example.com" },
      refreshUser,
    }),
  };
});

// Fetches a teacher profile of its own; nothing here is about it.
vi.mock("@/components/marketplace/PublicProfileUrlCard", () => ({
  PublicProfileUrlCard: () => null,
}));

const passwordInput = () =>
  document.getElementById("profile_current_password") as HTMLInputElement | null;

const emailInput = () => document.getElementById("email") as HTMLInputElement;

const submitProfile = () => fireEvent.click(screen.getByRole("button", { name: "احفظ التغييرات" }));

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  vi.clearAllMocks();
  patch.mockResolvedValue({});
  refreshUser.mockResolvedValue(undefined);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("settings · profile email", () => {
  it("asks nothing, and sends no password, for a name change", async () => {
    render(<SettingsPage />);

    expect(passwordInput()).toBeNull();

    fireEvent.change(document.getElementById("first_name") as HTMLInputElement, {
      target: { value: "هدى الجديدة" },
    });
    submitProfile();

    await waitFor(() => expect(patch).toHaveBeenCalledTimes(1));

    const [, body] = patch.mock.calls[0];
    expect(body).toEqual({ first_name: "هدى الجديدة", last_name: "علي", email: "huda@example.com" });
    expect(body).not.toHaveProperty("current_password");
  });

  it("asks for the current password once the address changes, and sends it", async () => {
    render(<SettingsPage />);

    fireEvent.change(emailInput(), { target: { value: "huda.new@example.com" } });

    expect(passwordInput()).not.toBeNull();

    fireEvent.change(passwordInput() as HTMLInputElement, { target: { value: "correct-horse" } });
    submitProfile();

    await waitFor(() => expect(patch).toHaveBeenCalledTimes(1));

    expect(patch.mock.calls[0][0]).toBe("/auth/me");
    expect(patch.mock.calls[0][1]).toMatchObject({
      email: "huda.new@example.com",
      current_password: "correct-horse",
    });

    // Saved: the new address is the baseline now, so the field goes away.
    await waitFor(() => expect(passwordInput()).toBeNull());
    expect(refreshUser).toHaveBeenCalled();
  });

  it("treats the same address in another case as unchanged", () => {
    render(<SettingsPage />);

    fireEvent.change(emailInput(), { target: { value: "Huda@Example.com" } });

    expect(passwordInput()).toBeNull();
  });

  it("puts the server's refusal under the password field", async () => {
    patch.mockRejectedValue(
      new ApiError("Unprocessable", 422, {
        message: "كلمة المرور غير صحيحة.",
        errors: { current_password: ["كلمة المرور غير صحيحة."] },
      }),
    );

    render(<SettingsPage />);

    fireEvent.change(emailInput(), { target: { value: "huda.new@example.com" } });
    fireEvent.change(passwordInput() as HTMLInputElement, { target: { value: "guess" } });
    submitProfile();

    expect(await screen.findByText("كلمة المرور غير صحيحة.")).toBeTruthy();
    // Still asking: nothing was saved, so the address is still a change.
    expect(passwordInput()).not.toBeNull();
    expect(refreshUser).not.toHaveBeenCalled();
  });
});
