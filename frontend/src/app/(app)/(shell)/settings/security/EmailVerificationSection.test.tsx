import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { EmailVerificationSection } from "./EmailVerificationSection";

const post = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { post: (path: string) => post(path) },
}));

let mockUser: { email: string; email_verified_at: string | null } | null = null;

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: mockUser }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { email: "student@example.com", email_verified_at: null };
  post.mockResolvedValue({ message: "Verification link sent." });
});

/*
  ⛔ An expired confirmation link landed on /login with «رابط التأكيد غير صالح أو
  انتهت مدّته» and nothing else — no way to ask for another. The resend route
  needs a session, so the button lives here.
*/
describe("EmailVerificationSection", () => {
  it("asks the server for a new link and says it was sent", async () => {
    render(<EmailVerificationSection />);

    fireEvent.click(screen.getByRole("button", { name: "أرسل رابطًا جديدًا" }));

    await waitFor(() => expect(screen.getByText("أرسلنا رابطاً جديداً إلى بريدك")).toBeTruthy());
    expect(post).toHaveBeenCalledWith("/auth/email/verification-notification");
  });

  it("offers nothing to a confirmed address", () => {
    mockUser = { email: "student@example.com", email_verified_at: "2026-09-01T10:00:00Z" };

    const { container } = render(<EmailVerificationSection />);

    expect(container.textContent).toBe("");
  });

  it("shows a refusal as a sentence, and keeps the button", async () => {
    post.mockRejectedValue(new Error("down"));
    render(<EmailVerificationSection />);

    fireEvent.click(screen.getByRole("button", { name: "أرسل رابطًا جديدًا" }));

    await waitFor(() => expect(screen.getByRole("button", { name: "أرسل رابطًا جديدًا" })).toBeTruthy());
    expect(screen.queryByText("أرسلنا رابطاً جديداً إلى بريدك")).toBeNull();
  });
});
