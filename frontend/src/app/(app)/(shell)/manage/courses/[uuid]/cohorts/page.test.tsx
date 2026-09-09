import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageCohortsPage from "./page";

/*
| لافتةُ «حصص بلا مجموعة» — والجملةُ التي كانت كاذبةً على الكورسِ المُبلَّغِ عنه.
|
| ⚠️ الذراعُ الثانيةُ في `CohortSessionVisibility` تُظهِرُ كلَّ حصّةٍ غيرِ مُسنَدةٍ
| في كورسٍ **لا مجموعاتِ له** — وهي `FR-036` نفسُها. فعلى مثلِ ذلك الكورسِ كانت
| اللافتةُ تقولُ «محجوبة عن جداول الطلاب» بصيغةِ الحاضرِ عن حصصٍ ظاهرةٍ تماماً،
| وتحتَها لا زرّ: زرُّ الإسنادِ مشروطٌ بوجودِ مجموعةٍ مفتوحةٍ، وهو بالضبطِ ما لم
| يكنْ عندَه. صيغةُ المستقبلِ صادقةٌ وقابلةٌ للتنفيذ.
*/

const list = vi.fn();
const transferRequests = vi.fn();
const unassignedSessions = vi.fn();

vi.mock("@/lib/cohorts", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/cohorts")>()),
  manageCohorts: {
    list: (uuid: string) => list(uuid),
    transferRequests: (uuid: string) => transferRequests(uuid),
    unassignedSessions: (uuid: string) => unassignedSessions(uuid),
  },
}));

const HIDDEN = {
  data: [{ uuid: "s-1", title: "حصة السبت", starts_at: "2026-10-03T13:00:00Z" }],
  meta: { total_hidden: 17, assignable: 14, already_held: 3 },
};

const group = {
  uuid: "g-1",
  name: "المجموعة الأولى",
  description: null,
  status: "open" as const,
  capacity: null,
  members_count: 0,
  seats_left: null,
  is_full: false,
  is_joinable: true,
  schedule_preview: [],
};

beforeEach(() => {
  vi.clearAllMocks();
  transferRequests.mockResolvedValue({ data: [] });
  unassignedSessions.mockResolvedValue(HIDDEN);
});

describe("hidden sessions banner", () => {
  it("says they are still visible, and names the way out, when there is no group yet", async () => {
    list.mockResolvedValue({ data: [] });

    // `use(params)` suspends, and a root with no fallback renders NOTHING until
    // the promise settles — `act` is what flushes it.
    await act(async () => {
      render(<ManageCohortsPage params={Promise.resolve({ uuid: "c-1" })} />);
    });

    expect(await screen.findByText("حصص بلا مجموعة")).toBeTruthy();
    expect(screen.getByText(/تظهر لطلابك/)).toBeTruthy();
    expect(screen.getByText(/أنشئها من النموذج أدناه/)).toBeTruthy();
    // The control the old wording promised and could not render.
    expect(screen.queryByLabelText("أسنِدها إلى")).toBeNull();
  });

  it("says they are hidden, and offers the assignment, once a group exists", async () => {
    list.mockResolvedValue({ data: [group] });

    // `use(params)` suspends, and a root with no fallback renders NOTHING until
    // the promise settles — `act` is what flushes it.
    await act(async () => {
      render(<ManageCohortsPage params={Promise.resolve({ uuid: "c-1" })} />);
    });

    expect(await screen.findByText("حصص محجوبة عن الطلاب")).toBeTruthy();
    expect(screen.getByText(/محجوبة عن\s+جداول الطلاب/)).toBeTruthy();
    expect(screen.getByLabelText("أسنِدها إلى")).toBeTruthy();
  });
});
