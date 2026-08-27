import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { RosterTab } from "./RosterTab";

/*
| زملاءُ المجموعة — FR-051 وحدَه لا يقيسُه خادم.
|
| ⚠️ الخادمُ يحذفُ المفتاحَ لمن لا مرتبةَ له، والشاشةُ هي التي تستطيعُ أن تُعيدَه
| صفراً بـ`rank ?? 0`. فسؤالُ «هل يُطبَعُ رقمٌ؟» يُجابُ هنا أو لا يُجابُ إطلاقاً.
*/

const roster = vi.fn();

vi.mock("@/lib/cohorts", () => ({
  cohorts: {
    roster: (uuid: string) => roster(uuid) as Promise<unknown>,
  },
}));

afterEach(() => {
  cleanup();
  roster.mockReset();
});

function member(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "u-1",
    name: "سارة أحمد",
    avatar_url: null,
    badges: [],
    ...overrides,
  };
}

describe("RosterTab", () => {
  it("shows a rank-less member with no number at all", async () => {
    roster.mockResolvedValue({
      members: [member({ uuid: "newcomer", name: "ليلى الجديدة" })],
    });

    render(<RosterTab cohortUuid="c-1" />);

    const row = await screen.findByText("ليلى الجديدة");
    const card = row.closest("li");

    expect(card).not.toBeNull();
    // ⚠️ ANY digit, Arabic-Indic or Latin. Asserting the absence of «٠» alone
    // passes against a screen printing «0», and asserting the absence of the
    // word «المركز» passes against «المركز —».
    expect(card?.textContent ?? "").not.toMatch(/[0-9٠-٩]/u);
  });

  it("draws the rank and the level when the member has them", async () => {
    roster.mockResolvedValue({
      members: [member({ rank: 7, level: 4 })],
    });

    render(<RosterTab cohortUuid="c-1" />);

    // Arabic-Indic digits, as everywhere else in the product.
    expect(await screen.findByText("٧")).toBeTruthy();
    expect(screen.getByText("٤")).toBeTruthy();
  });

  it("draws each badge the member holds and nothing where there are none", async () => {
    roster.mockResolvedValue({
      members: [
        member({ uuid: "a", name: "هند", badges: [{ key: "streak_7", name_ar: "أسبوعٌ متّصل", icon: null }] }),
        // A photo rather than the initial fallback, so the row's whole text
        // IS the name — the assertion below is then about badges and nothing else.
        member({ uuid: "b", name: "نور", avatar_url: "/storage/x.jpg", badges: [] }),
      ],
    });

    render(<RosterTab cohortUuid="c-1" />);

    expect(await screen.findByText("أسبوعٌ متّصل")).toBeTruthy();

    const bare = screen.getByText("نور").closest("li");

    // No dash, no placeholder — an empty badge list is a state.
    expect(bare?.textContent).toBe("نور");
  });

  /*
  | ⚠️ الفشلُ يُقال، لا يُخفى. `.catch(() => [])` كان سيرسمُ «لا زملاء بعد» فوقَ
  | رفضٍ أو انقطاع، فيقرأُ الطالبُ أنّ مجموعتَه فارغةٌ وهي ليست كذلك.
  */
  it("says the failure instead of drawing an empty group", async () => {
    roster.mockRejectedValue(new Error("boom"));

    render(<RosterTab cohortUuid="c-1" />);

    await waitFor(() => expect(screen.queryByText("لا زملاء بعد")).toBeNull());
    expect(roster).toHaveBeenCalledWith("c-1");
  });

  it("asks for nothing when the reader is in no group", () => {
    render(<RosterTab cohortUuid={null} />);

    expect(roster).not.toHaveBeenCalled();
  });
});
