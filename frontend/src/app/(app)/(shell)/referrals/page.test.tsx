import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ReferralsPage from "./page";
import { referralSignupLink, sanitiseReferralCode, whatsAppShareHref } from "@/lib/referral-link";

/*
| Spec 011 · FR-018 — the invitation link. The page used to show a code and ask
| the friend to type it into a field that did not exist; the link is what makes
| the invitation one tap instead of a transcription.
*/

const referralsApi = vi.hoisted(() => ({
  code: vi.fn(),
  list: vi.fn(),
}));

vi.mock("@/lib/referrals", () => ({ referrals: referralsApi }));

beforeEach(() => {
  referralsApi.code.mockResolvedValue({ code: "FRIEND23", completed_count: 0 });
  referralsApi.list.mockResolvedValue({ data: [] });
});

describe("ReferralsPage", () => {
  it("shows the signup link carrying this person's code, on the origin the page is open on", async () => {
    render(<ReferralsPage />);

    const expected = `${window.location.origin}/signup/student?ref=FRIEND23`;

    expect(await screen.findByText(expected)).toBeTruthy();
  });

  it("shares the link through WhatsApp with the message url-encoded", async () => {
    render(<ReferralsPage />);

    const share = await screen.findByRole("link", { name: /شارك عبر واتساب/ });
    const href = share.getAttribute("href") ?? "";

    expect(href.startsWith("https://wa.me/?text=")).toBe(true);
    // Encoded: no raw space, and the link survives the round trip whole.
    expect(href).not.toContain(" ");
    expect(decodeURIComponent(href.slice("https://wa.me/?text=".length))).toContain(
      `${window.location.origin}/signup/student?ref=FRIEND23`,
    );
    expect(share.getAttribute("target")).toBe("_blank");
    expect(share.getAttribute("rel")).toContain("noopener");
  });

  it("copies the link, and the code separately", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });

    render(<ReferralsPage />);

    fireEvent.click(await screen.findByRole("button", { name: "انسخ الرابط" }));
    await waitFor(() =>
      expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/signup/student?ref=FRIEND23`),
    );

    fireEvent.click(screen.getByRole("button", { name: "نسخ الكود" }));
    await waitFor(() => expect(writeText).toHaveBeenLastCalledWith("FRIEND23"));
  });
});

describe("referral-link helpers", () => {
  it("keeps only the code alphabet from an address-bar value, upper-cased and capped", () => {
    expect(sanitiseReferralCode("  ab-c23 ")).toBe("ABC23");
    expect(sanitiseReferralCode("<script>")).toBe("SCRIPT");
    expect(sanitiseReferralCode("A".repeat(40))).toHaveLength(12);
    expect(sanitiseReferralCode(undefined)).toBe("");
    expect(sanitiseReferralCode(["XY12", "ZZ"])).toBe("XY12");
  });

  it("builds the link without a doubled slash", () => {
    expect(referralSignupLink("https://example.test/", "AB12")).toBe(
      "https://example.test/signup/student?ref=AB12",
    );
  });

  it("encodes the whole WhatsApp message", () => {
    const href = whatsAppShareHref("https://example.test/signup/student?ref=AB12");

    expect(href).toContain(encodeURIComponent("https://example.test/signup/student?ref=AB12"));
  });
});
