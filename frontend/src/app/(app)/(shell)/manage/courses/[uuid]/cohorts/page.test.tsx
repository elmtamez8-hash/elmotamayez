import { act, fireEvent, render, screen } from "@testing-library/react";
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
const assignSessions = vi.fn();
const members = vi.fn();
const history = vi.fn();

vi.mock("@/lib/cohorts", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/cohorts")>()),
  manageCohorts: {
    list: (uuid: string) => list(uuid),
    transferRequests: (uuid: string) => transferRequests(uuid),
    unassignedSessions: (uuid: string) => unassignedSessions(uuid),
    assignSessions: (...args: unknown[]) => assignSessions(...args),
    members: (uuid: string) => members(uuid),
    history: (uuid: string) => history(uuid),
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
  assignSessions.mockResolvedValue({ assigned: 1 });
  members.mockResolvedValue({ data: [] });
  history.mockResolvedValue({ data: [] });
});

async function open() {
  // `use(params)` suspends, and a root with no fallback renders NOTHING until
  // the promise settles — `act` is what flushes it.
  await act(async () => {
    render(<ManageCohortsPage params={Promise.resolve({ uuid: "c-1" })} />);
  });
}

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

/*
| ⚠️ الإسنادُ كان «كلَّ الحصصِ إلى مجموعةٍ واحدة» بلا اختيار — فذهبت أربعَ عشرةَ
| حصّةً إلى المجموعةِ الأولى ولم يبقَ للثانيةِ شيء (مقيسٌ على كورسٍ حقيقيٍّ
| ٢٠٢٦-٠٩-٠٩). الاختيارُ لكلِّ حصّةٍ هو ما يجعلُ تقسيمَ الجدولِ على مجموعتين ممكناً.
*/
describe("assigning the hidden sessions", () => {
  const TWO = {
    data: [
      { uuid: "s-1", title: "السبت", starts_at: "2026-10-03T13:00:00Z" },
      { uuid: "s-2", title: "الأحد", starts_at: "2026-10-04T13:00:00Z" },
    ],
    meta: { total_hidden: 2, assignable: 2, already_held: 0 },
  };

  it("sends only what was ticked, not the whole list", async () => {
    list.mockResolvedValue({ data: [group] });
    unassignedSessions.mockResolvedValue(TWO);

    await open();

    // Nothing ticked: the button is shut rather than quietly meaning «all».
    expect(screen.getByRole("button", { name: /أسنِد/ }).hasAttribute("disabled")).toBe(true);

    fireEvent.click(screen.getAllByRole("checkbox")[0]);
    fireEvent.change(screen.getByLabelText("أسنِدها إلى"), { target: { value: "g-1" } });
    fireEvent.click(screen.getByRole("button", { name: /أسنِد/ }));

    expect(assignSessions).toHaveBeenCalledWith("c-1", "g-1", ["s-1"]);
  });

  it("ticks every one at once when that is what the teacher wants", async () => {
    list.mockResolvedValue({ data: [group] });
    unassignedSessions.mockResolvedValue(TWO);

    await open();

    fireEvent.click(screen.getByRole("button", { name: "حدّد الكل" }));
    fireEvent.change(screen.getByLabelText("أسنِدها إلى"), { target: { value: "g-1" } });
    fireEvent.click(screen.getByRole("button", { name: /أسنِد/ }));

    expect(assignSessions).toHaveBeenCalledWith("c-1", "g-1", ["s-1", "s-2"]);
  });
});

/*
| ⚠️ القائمةُ والسجلُّ كانا نداءَين لا يستدعيهما أيُّ ملفٍّ في `frontend/src` —
| «نقطةٌ لا يناديها أحدٌ ميزةٌ لا يملكُها أحد». ويُجلبانِ عندَ الفتحِ لا مع الصفحة:
| كورسٌ بثمانِ مجموعاتٍ كان سيفتحُ بسبعةَ عشرَ نداءً، ستّةَ عشرَ منها عن لوحاتٍ
| لم يفتحْها أحد.
*/
describe("the roster and the log", () => {
  it("asks for neither until the panel is opened, then asks once", async () => {
    list.mockResolvedValue({ data: [group] });

    await open();

    expect(members).not.toHaveBeenCalled();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /الطلاب/ }));
    });

    expect(members).toHaveBeenCalledWith("g-1");
    expect(members).toHaveBeenCalledTimes(1);

    // Closing and reopening reads what was already fetched.
    fireEvent.click(screen.getByRole("button", { name: /الطلاب/ }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /الطلاب/ }));
    });

    expect(members).toHaveBeenCalledTimes(1);
  });

  it("links each group to its own page", async () => {
    list.mockResolvedValue({ data: [group] });

    await open();

    const link = screen.getByRole("link", { name: "المجموعة الأولى" });

    expect(link.getAttribute("href")).toBe("/manage/cohorts/g-1");
  });
});
