import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { fireEvent } from "@testing-library/dom";

const hasAuthToken = vi.fn();
const joinWaitlist = vi.fn();

vi.mock("@/lib/api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api")>("@/lib/api");

  return { ...actual, hasAuthToken: () => hasAuthToken() };
});

vi.mock("@/lib/cohorts", () => ({
  cohorts: { joinWaitlist: (uuid: string) => joinWaitlist(uuid) },
}));

import { WaitlistButton } from "./WaitlistButton";

const COURSE = "cccc0000-0000-4000-8000-000000000001";

describe("WaitlistButton", () => {
  beforeEach(() => {
    hasAuthToken.mockReset();
    joinWaitlist.mockReset();
  });

  it("asks a signed-out visitor to sign in rather than pressing a button that 401s", async () => {
    /*
      ⚠️ الضغطُ بلا رمزٍ ينتهي بـ401 يُحوِّلُه معالِجُ الأخطاءِ إلى «انتهت
      جلستُك» — جملةٌ كاذبةٌ لمن لم تبدأْ له جلسةٌ قطّ، على صفحةٍ عامّةٍ يصلُها
      من لا حسابَ له أصلاً.
    */
    hasAuthToken.mockReturnValue(false);

    render(<WaitlistButton courseUuid={COURSE} />);

    await waitFor(() => expect(screen.getByText("سجّل دخولك")).toBeTruthy());
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("registers, and says the seat is not held", async () => {
    hasAuthToken.mockReturnValue(true);
    joinWaitlist.mockResolvedValue({ uuid: "x", joined_at: "2026-09-14T10:00:00Z" });

    render(<WaitlistButton courseUuid={COURSE} />);

    const button = await screen.findByRole("button");
    fireEvent.click(button);

    await waitFor(() => expect(screen.getByText("سجّلناك في الدَّور")).toBeTruthy());

    expect(joinWaitlist).toHaveBeenCalledWith(COURSE);

    /*
      ⚠️ **والجملةُ بعدَ الضغطِ كالتي قبلَه** (٠٣٤ · FR-027). دَورٌ يُقرَأُ وعداً
      هو وعدٌ يُخلَف، والوقتُ الذي يُصدَّقُ فيه الوعدُ هو ما بعدَ التسجيلِ لا
      ما قبلَه.
    */
    expect(screen.getByText(/ليس محجوزاً لك/)).toBeTruthy();
  });

  it("shows the server's own refusal, never a raw error", async () => {
    hasAuthToken.mockReturnValue(true);
    joinWaitlist.mockRejectedValue({ status: 422, body: { message: "في هذا الكورس مكان الآن" } });

    render(<WaitlistButton courseUuid={COURSE} />);

    fireEvent.click(await screen.findByRole("button"));

    await waitFor(() => expect(screen.getByText("تعذّر التسجيل في الدَّور")).toBeTruthy());

    // ولا تبقى الشاشةُ في حالةِ الانتظار: زرٌّ يدورُ للأبد عطبٌ بلا رسالة.
    expect(screen.getByRole("button").textContent).toContain("سجّلني في الدَّور");
  });
});
