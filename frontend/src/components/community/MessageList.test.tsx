import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { MessageList } from "./MessageList";
import { mergeMessages, type ChatMessage } from "@/lib/conversations";

/*
| Spec 010 · US2 — the live insert must not show a message twice.
|
| ⚠️ THE SAME MESSAGE ARRIVES TWICE BY DESIGN, and that is the whole test. The
| sender gets it in the response to their own POST, and then again a moment later
| through the socket — so a list that appends whatever arrives shows the sender
| their own sentence twice, on every message they send, for as long as the socket
| is up. It is invisible to every backend test: the table has one row.
|
| ⚠️ AND IT IS MEASURED ON `mergeMessages()` AND THROUGH THE RENDER BOTH. The
| function is where the rule lives; the render is what proves the component uses
| it rather than carrying a second copy of the rule of its own.
*/

function message(
  uuid: string,
  body: string,
  at: string,
  badges: { rank?: number | null; level?: number | null } = {},
): ChatMessage {
  return {
    uuid,
    body,
    sender_uuid: "me",
    sender_name: "سلمى",
    is_helpful: false,
    // ⚠️ NULL BY DEFAULT, because that is what most senders carry: a teacher, an
    // assistant, and any student the nightly roll-up has not seen yet.
    sender_rank: badges.rank ?? null,
    sender_level: badges.level ?? null,
    // Most messages are words and nothing else — null is the ordinary case.
    attachment: null,
    created_at: at,
  };
}

describe("mergeMessages", () => {
  it("keeps one copy of a message that arrived twice", () => {
    const first = message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00");

    const merged = mergeMessages([first], [first]);

    expect(merged).toHaveLength(1);
  });

  it("prefers the newer copy of a message it already had", () => {
    const original = message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00");
    const edited = { ...original, is_helpful: true };

    const merged = mergeMessages([original], [edited]);

    expect(merged).toHaveLength(1);
    expect(merged[0].is_helpful).toBe(true);
  });

  it("appends a genuinely new message after the ones already shown", () => {
    const older = message("m-1", "الأولى", "2026-08-23T10:00:00+00:00");
    const newer = message("m-2", "الثانية", "2026-08-23T10:05:00+00:00");

    expect(mergeMessages([older], [newer]).map((m) => m.body)).toEqual(["الأولى", "الثانية"]);
  });

  it("keeps the server order for two messages inside one second", () => {
    // Equal timestamps are the ordinary case in a live chat, and the server has
    // already ordered them by its monotonic key — the merge must not reshuffle.
    const at = "2026-08-23T10:00:00+00:00";
    const a = message("m-1", "الأولى", at);
    const b = message("m-2", "الثانية", at);

    expect(mergeMessages([], [a, b]).map((m) => m.body)).toEqual(["الأولى", "الثانية"]);
  });
});

describe("MessageList", () => {
  it("renders one node per message after a duplicate arrives", () => {
    const sent = message("m-1", "هل الحصّة غداً؟", "2026-08-23T10:00:00+00:00");

    render(
      <MessageList messages={mergeMessages([sent], [sent])} currentUserUuid="me" />,
    );

    expect(screen.getAllByTestId("chat-message")).toHaveLength(1);
    expect(screen.getByText("هل الحصّة غداً؟")).toBeDefined();
  });

  it("says so when there is nothing yet rather than rendering an empty list", () => {
    render(<MessageList messages={[]} currentUserUuid="me" />);

    expect(screen.queryAllByTestId("chat-message")).toHaveLength(0);
    expect(screen.getByText(/لا رسائل بعد/)).toBeDefined();
  });
});

describe("MessageList badges", () => {
  it("renders a sender with no rank and no level without breaking the line", () => {
    /*
    | ⚠️ THE ORDINARY CASE, NOT THE EDGE ONE. A teacher and an assistant are on no
    | board at all, and a student who signed up this morning has no row either —
    | so «no badge» is what most rows in a real room look like. A zero here reads
    | as «المركز ٠» beside the teacher's own name in front of the class.
    */
    render(
      <MessageList
        messages={[message("m-1", "سؤال", "2026-08-23T10:00:00+00:00")]}
        currentUserUuid="someone-else"
        showBadges
      />,
    );

    expect(screen.getByText("سؤال")).toBeDefined();
    expect(screen.getByText("سلمى")).toBeDefined();
    expect(screen.queryByText(/المركز/)).toBeNull();
    expect(screen.queryByText(/المستوى/)).toBeNull();
  });

  it("shows the level alone for a student the weekly board has not seen", () => {
    render(
      <MessageList
        messages={[message("m-1", "سؤال", "2026-08-23T10:00:00+00:00", { level: 4 })]}
        currentUserUuid="someone-else"
        showBadges
      />,
    );

    expect(screen.getByText("المستوى 4")).toBeDefined();
    expect(screen.queryByText(/المركز/)).toBeNull();
  });

  it("keeps the badges out of a private thread even when the data carries them", () => {
    // The server sends null in a private conversation; the flag is the second
    // half of that rule, so a payload that ever carried one still shows nothing.
    render(
      <MessageList
        messages={[message("m-1", "سؤال", "2026-08-23T10:00:00+00:00", { rank: 3, level: 4 })]}
        currentUserUuid="someone-else"
      />,
    );

    expect(screen.queryByText(/المركز/)).toBeNull();
    expect(screen.queryByText(/المستوى/)).toBeNull();
  });
});

/*
| صندوقُ التمرير، والنزولُ إلى الأحدث.
|
| كانت القائمةُ `<ul>` عاريةً في آخرِ الصفحة — تحتَ المسرحِ وأدواتِ المشاركِ وقائمةِ
| المشاركين — فأربعون رسالةً في حصّةٍ جماعيّةٍ تدفعُ حقلَ الكتابةِ شاشةً كاملةً إلى
| أسفل، وكلُّ رسالةٍ تصلُ عبرَ المقبسِ يبحثُ عنها القارئُ بيدِه.
|
| ⚠️ والنزولُ مشروط: سحبُ قارئٍ يقرأُ ما قاله المدرّسُ قبلَ خمسِ دقائقَ إلى الأسفلِ
| أسوأُ من تركِه يُمرِّر. jsdom لا يُخطِّطُ شيئاً — كلُّ الأبعادِ صفر — فالشرطُ
| يُقاسُ بضبطِ المقاساتِ بأنفسِنا، وهذا هو المكانُ الوحيدُ الذي يُقاسُ منه.
*/
describe("MessageList — following the conversation", () => {
  function boxOf(): HTMLElement {
    return screen.getAllByRole("list")[0];
  }

  function size(box: HTMLElement, scrollHeight: number, clientHeight: number): void {
    Object.defineProperty(box, "scrollHeight", { value: scrollHeight, configurable: true });
    Object.defineProperty(box, "clientHeight", { value: clientHeight, configurable: true });
  }

  it("scrolls a reader who is already at the bottom down to the newest message", () => {
    const first = [message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00")];

    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 400, 300);
    box.scrollTop = 100; // Exactly at the bottom of a 400px thread in a 300px box.

    rerender(
      <MessageList
        messages={[...first, message("m-2", "سؤال", "2026-08-23T10:01:00+00:00")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(box.scrollHeight);
  });

  it("leaves a reader who has scrolled up exactly where they were", () => {
    const first = [message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00")];

    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 400, 300);
    // Far from the bottom: they are reading something older on purpose.
    box.scrollTop = 0;

    rerender(
      <MessageList
        messages={[...first, message("m-2", "سؤال", "2026-08-23T10:01:00+00:00")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(0);
  });
});
