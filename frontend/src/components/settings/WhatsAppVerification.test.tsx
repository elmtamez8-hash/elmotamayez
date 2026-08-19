import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| SC-011 — the screen without which the channel is decorative.
|
| Every WhatsApp tick box on the settings grid does nothing at all until the
| number under it has been proven: the channel's canReach() asks for a VERIFIED
| number and refuses otherwise, so an unverified account records "skipped" for
| ever — silently, with no error anywhere and no red row to notice.
|
| Mocked at the module rather than at `fetch`: what these cases are about is two
| clicks and where a failure is displayed, and a network stub would make them
| about wiring instead.
*/

const request = vi.fn();
const confirm = vi.fn();

vi.mock("@/lib/notifications", () => ({
  contactVerification: {
    request: (...args: unknown[]) => request(...args),
    confirm: (...args: unknown[]) => confirm(...args),
  },
}));

vi.mock("@/lib/api", () => ({
  errorMessage: (_error: unknown, fallback: string) => fallback,
  fieldErrors: (error: unknown) =>
    (error as { fields?: Record<string, string> }).fields ?? {},
}));

const { WhatsAppVerification } = await import("./WhatsAppVerification");

describe("WhatsAppVerification", () => {
  beforeEach(() => {
    request.mockReset();
    confirm.mockReset();
  });

  it("sends the dial code and the number as one value", async () => {
    request.mockResolvedValue({ uuid: "v-1", expires_at: null });

    render(<WhatsAppVerification />);

    await userEvent.type(screen.getByLabelText("رقم الجوال"), "33123456");
    await userEvent.click(screen.getByRole("button", { name: "أرسِل رمز التأكيد" }));

    // One string, joined here — no endpoint should have to guess which country a
    // bare `33123456` belongs to.
    expect(request).toHaveBeenCalledWith("whatsapp", "+97433123456");
  });

  it("asks for the code only after one has been sent", async () => {
    request.mockResolvedValue({ uuid: "v-1", expires_at: null });

    render(<WhatsAppVerification />);

    expect(screen.queryByLabelText("رمز التأكيد")).toBeNull();

    await userEvent.type(screen.getByLabelText("رقم الجوال"), "33123456");
    await userEvent.click(screen.getByRole("button", { name: "أرسِل رمز التأكيد" }));

    expect(await screen.findByLabelText("رمز التأكيد")).toBeTruthy();
  });

  it("confirms with the code and reports success", async () => {
    request.mockResolvedValue({ uuid: "v-1", expires_at: null });
    confirm.mockResolvedValue({ uuid: "v-1", channel: "whatsapp", verified_at: "now" });

    render(<WhatsAppVerification />);

    await userEvent.type(screen.getByLabelText("رقم الجوال"), "33123456");
    await userEvent.click(screen.getByRole("button", { name: "أرسِل رمز التأكيد" }));
    await userEvent.type(await screen.findByLabelText("رمز التأكيد"), "123456");
    await userEvent.click(screen.getByRole("button", { name: "أكّد الرقم" }));

    expect(confirm).toHaveBeenCalledWith("v-1", "123456");
    expect(await screen.findByText(/تم تأكيد رقم واتساب/)).toBeTruthy();
  });

  it("puts a field error under its own field, not in a banner", async () => {
    request.mockRejectedValue({ fields: { contact_value: "رقم الهاتف غير صالح." } });

    render(<WhatsAppVerification />);

    await userEvent.type(screen.getByLabelText("رقم الجوال"), "12");
    await userEvent.click(screen.getByRole("button", { name: "أرسِل رمز التأكيد" }));

    // 422 lands under its field. A raw upstream sentence in a banner is the one
    // rule this product does not break.
    expect(await screen.findByText("رقم الهاتف غير صالح.")).toBeTruthy();
  });

  it("falls back to our own sentence when the failure names no field", async () => {
    request.mockRejectedValue({});

    render(<WhatsAppVerification />);

    await userEvent.type(screen.getByLabelText("رقم الجوال"), "33123456");
    await userEvent.click(screen.getByRole("button", { name: "أرسِل رمز التأكيد" }));

    expect(await screen.findByText("تعذّر إرسال رمز التأكيد.")).toBeTruthy();
  });
});
