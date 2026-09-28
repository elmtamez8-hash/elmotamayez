import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MessageList } from "./MessageList";
import { decideScroll, isNearBottom, nextPinned, SETTLE_MS, shapeOf } from "@/lib/chat-scroll";
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

  it("labels a line the student's guardian wrote, and only that line", () => {
    // 2026-09-28: a guardian writes AS the child, so the teacher must be able to
    // tell the parent's words from the student's in one thread.
    const parent = { ...message("m-1", "متى الامتحان؟", "2026-08-23T10:00:00+00:00"), sender_uuid: "p", sender_name: "أبو كريم", sent_by_guardian: true };
    const child = { ...message("m-2", "شكراً", "2026-08-23T10:01:00+00:00"), sender_uuid: "k", sender_name: "كريم" };

    render(<MessageList messages={[parent, child]} currentUserUuid="teacher" />);

    expect(screen.getAllByText("وليّ الأمر")).toHaveLength(1);
    expect(screen.getByText("أبو كريم")).toBeTruthy();
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
  function from(uuid: string, body: string, at: string, sender: string): ChatMessage {
    return { ...message(uuid, body, at), sender_uuid: sender, sender_name: sender === "me" ? "أنا" : "المدرّس" };
  }

  function boxOf(): HTMLElement {
    return screen.getByTestId("chat-scroll");
  }

  function size(box: HTMLElement, scrollHeight: number, clientHeight: number): void {
    Object.defineProperty(box, "scrollHeight", { value: scrollHeight, configurable: true });
    Object.defineProperty(box, "clientHeight", { value: clientHeight, configurable: true });
  }

  /** The reader moves: the position is recorded from the scroll event, as in a browser. */
  function scrollTo(box: HTMLElement, top: number): void {
    box.scrollTop = top;
    fireEvent.scroll(box);
  }

  const first = [from("m-1", "أهلاً", "2026-08-23T10:00:00+00:00", "them")];

  it("scrolls a reader who is already at the bottom down to the newest message", () => {
    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 400, 300);
    scrollTo(box, 100); // Exactly at the bottom of a 400px thread in a 300px box.

    size(box, 470, 300);
    rerender(
      <MessageList
        messages={[...first, from("m-2", "سؤال", "2026-08-23T10:01:00+00:00", "them")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(470);
  });

  /*
  | ⚠️ بلاغُ ٢٠٢٦-٠٩-٢٨: «رسالةٌ فيها إيموجي لا تظهرُ إلّا بعدَ التحديث». كان سؤالُ
  | «هل القارئُ في الأسفل؟» يُسألُ بعدَ رسمِ الفقاعةِ الجديدة، فيُحسَبُ طولُها هي
  | عليه: سطرٌ قصيرٌ يتبعُه النزول، وسطرٌ أطولُ ببضعِ بكسلات — والإيموجي يرفعُ
  | السطر — يُحكَمُ عليه «ابتعدَ القارئ» فيبقى تحتَ الحافّة. والجوابُ الآنَ عمّا
  | كانَ قبلَ وصولِها.
  */
  it("still follows when the new message is taller than the threshold on its own", () => {
    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 400, 300);
    scrollTo(box, 100);

    // A 150px bubble: measured AFTER it arrived, the reader would be 150px from the
    // bottom — further than the threshold — and would have been left behind.
    size(box, 550, 300);
    rerender(
      <MessageList
        messages={[...first, from("m-2", "تمام 👍🏽👍🏽👍🏽", "2026-08-23T10:01:00+00:00", "them")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(550);
    expect(screen.queryByRole("button", { name: /رسائل جديدة/ })).toBeNull();
  });

  it("leaves a reader who has scrolled up where they were, and says a message came", () => {
    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 1000, 300);
    scrollTo(box, 0); // Reading something older on purpose.

    size(box, 1070, 300);
    rerender(
      <MessageList
        messages={[...first, from("m-2", "سؤال", "2026-08-23T10:01:00+00:00", "them")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(0);

    const pill = screen.getByRole("button", { name: /رسائل جديدة/ });

    fireEvent.click(pill);

    expect(box.scrollTop).toBe(1070);
    expect(screen.queryByRole("button", { name: /رسائل جديدة/ })).toBeNull();
  });

  it("always takes the reader down to a message they sent themselves", () => {
    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);

    const box = boxOf();
    size(box, 1000, 300);
    scrollTo(box, 0);

    size(box, 1070, 300);
    rerender(
      <MessageList
        messages={[...first, from("m-2", "ردّي", "2026-08-23T10:01:00+00:00", "me")]}
        currentUserUuid="me"
      />,
    );

    expect(box.scrollTop).toBe(1070);
  });

  it("keeps the reader on the same line when an older page arrives above", () => {
    const { rerender } = render(<MessageList messages={first} currentUserUuid="me" onLoadOlder={() => {}} />);

    const box = boxOf();
    size(box, 1000, 300);
    scrollTo(box, 40);

    // The older page adds 600px above what they were reading.
    size(box, 1600, 300);
    rerender(
      <MessageList
        messages={[from("m-0", "قديمة", "2026-08-23T09:00:00+00:00", "them"), ...first]}
        currentUserUuid="me"
        onLoadOlder={() => {}}
      />,
    );

    expect(box.scrollTop).toBe(640);
  });

  it("starts watching the list for late-loading pictures once the first message arrives", () => {
    const observed: Element[] = [];

    class FakeResizeObserver {
      observe(element: Element) {
        observed.push(element);
      }
      disconnect() {}
      unobserve() {}
    }

    const original = globalThis.ResizeObserver;
    globalThis.ResizeObserver = FakeResizeObserver as unknown as typeof ResizeObserver;

    try {
      // A room's chat mounts empty: there is no list yet to watch.
      const { rerender } = render(<MessageList messages={[]} currentUserUuid="me" />);
      expect(observed).toHaveLength(0);

      rerender(<MessageList messages={first} currentUserUuid="me" />);

      expect(observed).toEqual([screen.getByRole("list"), boxOf()]);
    } finally {
      globalThis.ResizeObserver = original;
    }
  });

  /*
  | ⚠️ اختبارٌ حيٌّ بحسابين (٢٠٢٦-٠٩-٢٨): وصلت رسالةٌ فيها صورة، فنزلت القائمةُ إلى
  | الأسفل قبلَ أن تُحمَّلَ الصورة، ثمّ طالت القائمةُ ٢٠٠ بكسل فبقيَ القارئُ فوقَ
  | الأسفلِ بطولِها. حدثُ التمريرِ الذي أطلقه نزولُنا نحن يصلُ في الإطارِ التالي
  | ويقرأُ الطولَ بعدَ نموِّ الصورة، فحُكِمَ على القارئِ «ابتعد» ولم يتبعه أحد.
  | jsdom لا يُحدِّدُ `scrollTop` بحدٍّ أعلى كما يفعلُ المتصفّح، فنفعلُه هنا بأيدينا.
  */
  describe("a picture that grows after the message arrived", () => {
    let callbacks: Array<() => void> = [];
    let original: typeof ResizeObserver;

    class CapturingResizeObserver {
      constructor(private readonly callback: () => void) {}
      observe() {
        callbacks.push(this.callback);
      }
      disconnect() {}
      unobserve() {}
    }

    beforeEach(() => {
      callbacks = [];
      original = globalThis.ResizeObserver;
      globalThis.ResizeObserver = CapturingResizeObserver as unknown as typeof ResizeObserver;
    });

    afterEach(() => {
      globalThis.ResizeObserver = original;
    });

    /** A box whose `scrollTop` is clamped to its content, as a browser's is. */
    function layout(box: HTMLElement, clientHeight: number): { grow: (to: number) => void } {
      let height = 0;
      let top = 0;

      Object.defineProperty(box, "clientHeight", { value: clientHeight, configurable: true });
      Object.defineProperty(box, "scrollHeight", { get: () => height, configurable: true });
      Object.defineProperty(box, "scrollTop", {
        get: () => top,
        set: (value: number) => {
          top = Math.max(0, Math.min(value, height - clientHeight));
        },
        configurable: true,
      });

      return {
        grow: (to: number) => {
          height = to;
        },
      };
    }

    function distanceFromBottom(box: HTMLElement): number {
      return box.scrollHeight - box.clientHeight - box.scrollTop;
    }

    /** The picture finished loading: the list grows and the observer reports it. */
    function pictureLoads(): void {
      act(() => callbacks.forEach((callback) => callback()));
    }

    const picture = (uuid: string, sender: string): ChatMessage => ({
      ...from(uuid, "", "2026-08-23T10:01:00+00:00", sender),
      attachment: { kind: "image", url: "https://files.test/p.jpg", duration_seconds: null },
    });

    it.each([
      ["received", "them"],
      ["sent", "me"],
    ])("keeps a reader at the bottom pinned when a %s picture loads late", (_, sender) => {
      const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);
      const box = boxOf();
      const { grow } = layout(box, 300);

      grow(400);
      scrollTo(box, 100); // At the bottom.

      // The bubble arrives with an empty <img>: 60px, and the hook follows it down.
      grow(460);
      rerender(<MessageList messages={[...first, picture("m-2", sender)]} currentUserUuid="me" />);
      expect(distanceFromBottom(box)).toBe(0);

      // The picture loads (+200px) BEFORE the browser delivers the scroll event
      // of our own scroll — which therefore reads 200px from the bottom.
      grow(660);
      fireEvent.scroll(box);
      pictureLoads();

      expect(distanceFromBottom(box)).toBe(0);
      expect(screen.queryByRole("button", { name: /رسائل جديدة/ })).toBeNull();
    });

    /*
    | ⚠️ الاختبارُ الحيُّ الثاني على #278: أرسلَ الطالبُ صورة، فانتهى النزولُ الناعمُ
    | قبلَ الأسفلِ الحقيقيِّ بـ ٥٩ بكسل (طولُ الفقاعةِ الجديدة)، والصورةُ «الكسولة»
    | بلا حجمٍ تحتَ حافّةِ الصندوقِ فلم تُحمَّلْ أبداً، فلم يكبرْ شيءٌ ولم يتحرّكْ شيء.
    */
    it("lands a smooth scroll at the true bottom even when nothing grows afterwards", () => {
      vi.useFakeTimers();

      try {
        const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);
        const box = boxOf();
        const { grow } = layout(box, 300);
        let smoothTarget: number | null = null;

        // A smooth scroll that aims at the bottom as it was when it started.
        box.scrollTo = ((options: ScrollToOptions) => {
          smoothTarget = options.top ?? null;
        }) as typeof box.scrollTo;

        grow(400);
        scrollTo(box, 100); // At the bottom, following.

        // The reader's own picture: the smooth scroll starts toward 460…
        grow(460);
        rerender(<MessageList messages={[...first, picture("m-2", "me")]} currentUserUuid="me" />);
        expect(smoothTarget).toBe(460);

        // …the bubble's last 59px are laid out after it started, and the animation
        // ends where it was aimed: short of the bottom. No observer fires — a
        // picture that never loads grows nothing.
        grow(519);
        scrollTo(box, 160);
        expect(distanceFromBottom(box)).toBe(59);

        fireEvent(box, new Event("scrollend"));

        expect(distanceFromBottom(box)).toBe(0);
      } finally {
        vi.useRealTimers();
      }
    });

    it("asserts the bottom on a timer where the browser has no scrollend", () => {
      vi.useFakeTimers();

      try {
        const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);
        const box = boxOf();
        const { grow } = layout(box, 300);

        box.scrollTo = (() => undefined) as typeof box.scrollTo;

        grow(400);
        scrollTo(box, 100);

        grow(460);
        rerender(<MessageList messages={[...first, picture("m-2", "them")]} currentUserUuid="me" />);
        grow(519);

        act(() => {
          vi.advanceTimersByTime(SETTLE_MS);
        });

        expect(distanceFromBottom(box)).toBe(0);
      } finally {
        vi.useRealTimers();
      }
    });

    it("does not re-assert the bottom for a reader who scrolled up during the animation", () => {
      vi.useFakeTimers();

      try {
        const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);
        const box = boxOf();
        const { grow } = layout(box, 300);

        box.scrollTo = (() => undefined) as typeof box.scrollTo;

        grow(1000);
        scrollTo(box, 700);

        grow(1060);
        rerender(<MessageList messages={[...first, picture("m-2", "them")]} currentUserUuid="me" />);

        scrollTo(box, 200); // The reader takes over and goes up.
        fireEvent(box, new Event("scrollend"));

        expect(box.scrollTop).toBe(200);
      } finally {
        vi.useRealTimers();
      }
    });

    it("loads a picture at once, in a reserved box, never lazily at 0×0", () => {
      render(<MessageList messages={[...first, picture("m-2", "them")]} currentUserUuid="me" />);

      const image = screen.getByAltText("صورة مرفقة");

      expect(image.getAttribute("loading")).not.toBe("lazy");
      expect(image.className).toContain("h-48");
      expect(image.className).toContain("w-48");

      fireEvent.load(image);

      expect(image.className).not.toContain("h-48");
    });

    it("does not yank a reader who scrolled up when a picture loads late", () => {
      const { rerender } = render(<MessageList messages={first} currentUserUuid="me" />);
      const box = boxOf();
      const { grow } = layout(box, 300);

      grow(1000);
      scrollTo(box, 700); // At the bottom…
      scrollTo(box, 200); // …then up into the history, on purpose.

      grow(1060);
      rerender(<MessageList messages={[...first, picture("m-2", "them")]} currentUserUuid="me" />);

      grow(1260);
      pictureLoads();

      expect(box.scrollTop).toBe(200);
      expect(screen.getByRole("button", { name: /رسائل جديدة/ })).toBeTruthy();
    });
  });

  it("is the only scroll box on the screen", () => {
    render(<MessageList size="fill" messages={first} currentUserUuid="me" />);

    // The list itself no longer scrolls; the one box around it does.
    expect(screen.getByRole("list").className).not.toContain("overflow");
    expect(boxOf().className).toContain("overflow-y-auto");
    expect(boxOf().className).toContain("min-h-0");
  });
});

describe("decideScroll", () => {
  const shape = (...uuids: string[]) => shapeOf(uuids);

  it("opens a thread at its newest message", () => {
    expect(decideScroll(null, shape("a", "b"), { wasNearBottom: false, lastIsMine: false })).toBe(
      "bottom-instant",
    );
  });

  it("follows, pills, or keeps the offset", () => {
    const before = shape("a", "b");

    expect(decideScroll(before, shape("a", "b", "c"), { wasNearBottom: true, lastIsMine: false })).toBe("bottom-smooth");
    expect(decideScroll(before, shape("a", "b", "c"), { wasNearBottom: false, lastIsMine: false })).toBe("pill");
    expect(decideScroll(before, shape("a", "b", "c"), { wasNearBottom: false, lastIsMine: true })).toBe("bottom-smooth");
    expect(decideScroll(before, shape("z", "a", "b"), { wasNearBottom: false, lastIsMine: false })).toBe("keep-offset");
    // A message hidden in the middle moves neither end.
    expect(decideScroll(shape("a", "b", "c"), shape("a", "c"), { wasNearBottom: false, lastIsMine: false })).toBe("none");
    // Hiding the NEWEST moves the tail and is still not «something new».
    expect(decideScroll(shape("a", "b", "c"), shape("a", "b"), { wasNearBottom: false, lastIsMine: false })).toBe("none");
    expect(decideScroll(shape("a", "b", "c"), shape("a", "b"), { wasNearBottom: true, lastIsMine: true })).toBe("none");
  });

  it("unpins only when the reader moved up, never on a late report of our own scroll", () => {
    const far = { scrollHeight: 1000, clientHeight: 300 };

    // Our own scroll, reported after a picture grew the list: it moved DOWN.
    expect(nextPinned(true, 400, { ...far, scrollTop: 500 })).toBe(true);
    // The reader scrolled up, or the event reports no movement at all.
    expect(nextPinned(true, 500, { ...far, scrollTop: 300 })).toBe(false);
    expect(nextPinned(true, 500, { ...far, scrollTop: 500 })).toBe(false);
    // Moving down while still far keeps a reader who was not following unpinned.
    expect(nextPinned(false, 100, { ...far, scrollTop: 300 })).toBe(false);
    // Near the bottom always follows.
    expect(nextPinned(false, 900, { ...far, scrollTop: 650 })).toBe(true);
  });

  it("counts 120px from the bottom as still following", () => {
    expect(isNearBottom({ scrollHeight: 1000, scrollTop: 580, clientHeight: 300 })).toBe(true);
    expect(isNearBottom({ scrollHeight: 1000, scrollTop: 579, clientHeight: 300 })).toBe(false);
  });
});
