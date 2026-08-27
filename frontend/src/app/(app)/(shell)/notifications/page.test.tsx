import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import NotificationsPage from "./page";

/*
| مركزُ الإشعارات — تبويباتٌ بالموضوع، مشتقّةٌ ممّا وصلَ القارئَ فعلاً.
|
| ⚠️ المشكلةُ لم تكنِ الترتيبَ بل الحائط: طالبٌ واحدٌ عندَه أربعةٌ وستّون غيرَ
| مقروء، وصفحتُه الأولى فيها سبعةُ أنواعٍ مختلفةٍ بالشكلِ نفسِه صفّاً تحتَ صفّ.
|
| ⚠️ AND NONE OF THIS IS REACHABLE FROM A BACKEND TEST. The server sends a
| correct `categories` array and knows nothing about what is drawn from it: a
| strip that ignored the counts, or a row that renders as a link to nowhere, is a
| perfectly good `200`.
*/

const list = vi.fn();
const markRead = vi.fn();
const markAllRead = vi.fn();

vi.mock("@/lib/notifications", () => ({
  notifications: {
    list: (params: Record<string, unknown>) => list(params),
    markRead: (uuid: string) => markRead(uuid),
    markAllRead: () => markAllRead(),
  },
}));

function item(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "n-1",
    type: "exam_result",
    type_label: "نتيجة اختبار",
    category: { key: "study", label: "الدراسة" },
    title: "ظهرت نتيجتك",
    body: "حصلت على ٨٢٪ في اختبار الوحدة الأولى.",
    action_url: "/exams/e-1/result",
    subject: null,
    workspace: { uuid: "w-1", name: "أكاديمية التميز" },
    read_at: null,
    created_at: new Date().toISOString(),
    ...overrides,
  };
}

function page(overrides: Record<string, unknown> = {}) {
  return {
    data: [item()],
    meta: {
      current_page: 1,
      last_page: 1,
      total: 1,
      unread_count: 3,
      categories: [
        { key: "study", label: "الدراسة", unread: 2, total: 3 },
        { key: "sessions", label: "الحصص والمواعيد", unread: 1, total: 4 },
      ],
      ...(overrides.meta as object | undefined),
    },
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  window.history.replaceState(null, "", "/notifications");
  list.mockResolvedValue(page());
  markRead.mockResolvedValue({ unread_count: 2 });
  markAllRead.mockResolvedValue({ unread_count: 0 });
});

describe("NotificationsPage", () => {
  it("draws a tab per subject the server offered, and never one it did not", async () => {
    render(<NotificationsPage />);

    expect(await screen.findByRole("tab", { name: /الدراسة/ })).toBeTruthy();
    expect(screen.getByRole("tab", { name: /الحصص والمواعيد/ })).toBeTruthy();

    // The reader has no settlement notices, so no such tab exists — a student
    // never receives one, and a fixed strip would offer them an empty page.
    expect(screen.queryByRole("tab", { name: /الأجر/ })).toBeNull();
    expect(screen.queryByRole("tab", { name: /الحساب/ })).toBeNull();
  });

  /*
   | ⚠️ THE COUNT IS THE POINT, NOT THE HIDING. Without it the tabs are seven
   | guesses; with it the strip says where the unread ones are before the reader
   | presses anything.
  */
  it("carries each subject's unread count on its tab", async () => {
    render(<NotificationsPage />);

    const study = await screen.findByRole("tab", { name: /الدراسة/ });

    expect(study.textContent).toContain("2");
    expect(screen.getByRole("tab", { name: /الحصص والمواعيد/ }).textContent).toContain("1");
  });

  it("asks the server for one subject when its tab is opened, from page one", async () => {
    render(<NotificationsPage />);

    fireEvent.click(await screen.findByRole("tab", { name: /الحصص والمواعيد/ }));

    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith({ page: 1, unread: false, category: "sessions" }),
    );
  });

  /*
   | ⚠️ «الكل» IS THE ABSENCE OF A CATEGORY, NOT A VALUE. Sent as `category=all`
   | the server would resolve nothing and empty the feed — the direction its
   | filter deliberately fails in.
  */
  it("sends no category at all for «الكل»", async () => {
    render(<NotificationsPage />);

    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(list.mock.calls[0][0]).toEqual({ page: 1, unread: false, category: undefined });
  });

  /*
   | ⚠️ `waitFor` ON THE SELECTION, NOT ONLY ON THE TAB EXISTING.
   |
   | `useTabParam` reads `location.search` in an EFFECT — not with
   | `useSearchParams`, which opts the page out of prerendering without a
   | `<Suspense>` — and the strip is built from a fetch, so the tab appears one
   | render before the parameter can select it. `findByRole` resolves the moment
   | it exists; asserting there passed alone and failed inside the full suite,
   | which is a slower machine finding the same gap.
  */
  it("opens on the tab named in ?tab=", async () => {
    window.history.replaceState(null, "", "/notifications?tab=sessions");

    render(<NotificationsPage />);

    await waitFor(() =>
      expect(
        screen.getByRole("tab", { name: /الحصص والمواعيد/ }).getAttribute("aria-selected"),
      ).toBe("true"),
    );
  });

  /*
   | ⚠️ A ROW WITH NOWHERE TO GO IS NOT A LINK. Half these notifications carry no
   | `action_url` — a card that looks pressable and does nothing is the defect
   | `LessonRow` was written to end.
  */
  it("links a row that has a destination and leaves one that has none alone", async () => {
    list.mockResolvedValue(
      page({ data: [item(), item({ uuid: "n-2", title: "لا وجهة", action_url: null })] }),
    );

    render(<NotificationsPage />);

    await screen.findByText("ظهرت نتيجتك");

    const hrefs = screen.getAllByRole("link").map((a) => a.getAttribute("href"));

    expect(hrefs).toContain("/exams/e-1/result");
    expect(screen.getByText("لا وجهة").closest("a")).toBeNull();
  });

  it("says unread in a word, not by a tint alone", async () => {
    render(<NotificationsPage />);

    expect(await screen.findByText("جديد")).toBeTruthy();
  });

  /*
   | ⚠️ THE TAB'S COUNTER MOVES WITH THE ROW. Left alone the strip keeps claiming
   | a number the list under it no longer shows — the drift the bell had before
   | the request layer began announcing reads.
  */
  it("takes the row's own subject down by one when it is marked read", async () => {
    render(<NotificationsPage />);

    fireEvent.click(await screen.findByRole("button", { name: "تعليم كمقروء" }));

    await waitFor(() =>
      expect(screen.getByRole("tab", { name: /الدراسة/ }).textContent).toContain("1"),
    );

    // And the other subject is untouched — the row belonged to «الدراسة».
    expect(screen.getByRole("tab", { name: /الحصص والمواعيد/ }).textContent).toContain("1");
  });

  it("groups the feed by day rather than running it together", async () => {
    render(<NotificationsPage />);

    expect(await screen.findByRole("heading", { name: "اليوم" })).toBeTruthy();
  });

  /*
   | ⚠️ AN EMPTY SUBJECT SAYS WHICH ONE. «لا إشعارات بعد» under an open tab
   | contradicts the strip above it, which is still counting.
  */
  it("names the open subject when it has nothing in it", async () => {
    window.history.replaceState(null, "", "/notifications?tab=sessions");
    list.mockResolvedValue(page({ data: [] }));

    render(<NotificationsPage />);

    expect(await screen.findByText(/لا إشعارات في «الحصص والمواعيد»/)).toBeTruthy();
  });

  it("draws no strip at all when the server offers no subject", async () => {
    list.mockResolvedValue(page({ meta: { categories: [] } }));

    render(<NotificationsPage />);

    await screen.findByText("ظهرت نتيجتك");

    expect(screen.queryByRole("tablist")).toBeNull();
  });

  it("says why when the fetch fails, instead of rendering nothing", async () => {
    list.mockRejectedValue(new Error("network"));

    render(<NotificationsPage />);

    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
  });

  /*
   | ⚠️ THE SUBJECT IS WRITTEN, NOT ONLY DRAWN. The icon is emphasis that lands
   | before a word is read; on its own it is a guess for a reader who does not
   | know the product yet, and nothing at all to a screen reader — so every glyph
   | on this page is `aria-hidden` and its subject is beside it in Arabic.
  */
  it("names each row's subject in words beside its icon", async () => {
    render(<NotificationsPage />);

    // Once on the row, once on its tab.
    await waitFor(() => expect(screen.getAllByText("الدراسة").length).toBeGreaterThan(1));

    const glyphs = document.querySelectorAll('[aria-hidden="true"] svg');
    expect(glyphs.length).toBeGreaterThan(0);
  });

  /*
   | ⚠️ A ROW WHOSE TYPE NOBODY CLASSIFIED STILL ARRIVES AND STILL READS. The
   | server sends `category: null` rather than guessing, and the screen falls
   | back to the bell — dropping the row, or crashing on a missing key, would
   | lose somebody's message over a bookkeeping gap.
  */
  it("renders a row the server could not file", async () => {
    list.mockResolvedValue(
      page({ data: [item({ uuid: "n-9", title: "نوع جديد", category: null })] }),
    );

    render(<NotificationsPage />);

    expect(await screen.findByText("نوع جديد")).toBeTruthy();
  });
});
