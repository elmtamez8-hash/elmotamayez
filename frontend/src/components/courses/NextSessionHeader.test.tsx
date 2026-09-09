import { act, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { NextSessionHeader } from "./NextSessionHeader";
import type { ClassSession } from "@/lib/class-sessions";

/*
| رأسُ الحصّةِ القادمة (US2 · FR-015 · SC-016).
|
| ⚠️ THE DOOR IS THE SERVER'S ANSWER, AND ONLY A COMPONENT TEST CAN SEE THAT IT
| IS READ. The backend does not know this button exists; Playwright builds for
| production and needs two servers, so nobody runs it inside a development loop.
| A header that drew the button from «seconds left is small» would pass every
| backend assertion in the product and refuse every student on a slow clock.
*/

function session(overrides: Partial<ClassSession> = {}): ClassSession {
  return {
    uuid: "s-1",
    title: "الدرس الثالث",
    type: "group",
    type_label: "جماعية",
    status: "scheduled",
    status_label: "مجدولة",
    room_closed: false,
    join_open: false,
    // ٠٢٩: الحقلانِ يأتيانِ من الخادمِ على كلِّ حصّةٍ الآن. هذه الترويسةُ تقرأُ
    // نظيرَهما العلويَّ من `‎/courses/{c}/next-session` عبرَ خصائصِها، فالقيمتانِ
    // هنا وصفٌ صادقٌ لحصّةٍ قادمةٍ لم يفتحْ بابُها — لا صفرٌ يعني «مفتوحٌ الآن».
    seconds_until_join_open: 900,
    seconds_until_start: 1800,
    starts_at: "2026-09-01T16:00:00+03:00",
    ends_at: "2026-09-01T17:00:00+03:00",
    duration_minutes: 60,
    timezone: "Asia/Qatar",
    seats: { total: 10, taken: 3, available: 7 },
    my_booking: null,
    recording: null,
    ...overrides,
  };
}

describe("NextSessionHeader", () => {
  it("says there is no next session rather than counting down to nothing", () => {
    render(<NextSessionHeader session={null} secondsUntilStart={0} />);

    expect(screen.getByText(/لا حصّة قادمة/)).toBeTruthy();
    expect(screen.queryByRole("timer")).toBeNull();
  });

  /*
   | ⚠️ THE BUTTON FOLLOWS `join_open`, NOT THE CLOCK. Three hours left and the
   | server says the door is open — a header computing its own window would
   | refuse to draw it, and the student would sit outside a room that was open.
  */
  it("offers the room when the server says the door is open, whatever the countdown says", () => {
    render(<NextSessionHeader session={session({ join_open: true })} secondsUntilStart={3 * 3600} />);

    expect(screen.getByRole("link", { name: "دخول الغرفة" }).getAttribute("href")).toBe(
      "/sessions/s-1/room",
    );
  });

  it("withholds it while the door is shut, and says so instead", () => {
    render(<NextSessionHeader session={session()} secondsUntilStart={60} />);

    expect(screen.queryByRole("link", { name: "دخول الغرفة" })).toBeNull();
    expect(screen.getByText(/يُفتح الدخول قبل الموعد/)).toBeTruthy();
  });

  /*
   | ⚠️ A CLOSED ROOM OUTRANKS A `live` STATUS, ON THE BADGE AND ON THE BUTTON.
   | `CloseClassSessionJob` runs at `ends_at` plus the join window, so a teacher
   | who ends the broadcast early leaves a session `live` with the room deleted —
   | and the page used to badge a finished lesson «جارية» and go on offering a
   | button that answers «تعذّر الدخول».
  */
  it("shuts the door on a closed room even while the status still says live", () => {
    render(
      <NextSessionHeader
        session={session({ status: "live", status_label: "جارية", room_closed: true, join_open: true })}
        secondsUntilStart={0}
      />,
    );

    expect(screen.queryByRole("link", { name: "دخول الغرفة" })).toBeNull();
    expect(screen.queryByText("جارية")).toBeNull();
    expect(screen.getByText("انتهت")).toBeTruthy();
    expect(screen.getByText(/أُغلقت الغرفة/)).toBeTruthy();
  });

  it("says the lesson has started instead of counting zeroes for ever", () => {
    render(<NextSessionHeader session={session()} secondsUntilStart={0} />);

    expect(screen.getByText("بدأت الآن")).toBeTruthy();
  });

  /*
   | ⚠️ THE DOOR HAS TO OPEN ON A PAGE NOBODY RELOADED. `join_open` is answered
   | once, at fetch — so a student who opens the course a few minutes early would
   | watch the countdown reach «بدأت الآن» while the footer still said the door
   | was shut, for ever, until F5. The server sends «how long until then» and
   | this ticks it down; the browser's own clock is never consulted (SC-016).
   |
   | Fake timers plus `act`, never `userEvent` — that one awaits real timers
   | between its simulated steps and hangs on a clock nothing advances.
  */
  it("opens the door when the server's own countdown reaches zero, with no refetch", () => {
    vi.useFakeTimers();

    render(
      <NextSessionHeader session={session()} secondsUntilStart={20} secondsUntilJoinOpen={3} />,
    );

    expect(screen.queryByRole("link", { name: "دخول الغرفة" })).toBeNull();

    act(() => {
      vi.advanceTimersByTime(4000);
    });

    expect(screen.getByRole("link", { name: "دخول الغرفة" })).toBeTruthy();
  });

  /*
   | ⚠️ `null` MEANS «NEVER AGAIN», AND A CLIENT THAT TREATED IT AS A NUMBER
   | WOULD DRAW THE BUTTON EVENTUALLY. A closed room and a window already past
   | both arrive as null.
  */
  it("never opens the door when the server says it will not open again", () => {
    vi.useFakeTimers();

    render(
      <NextSessionHeader
        session={session({ room_closed: true })}
        secondsUntilStart={5}
        secondsUntilJoinOpen={null}
      />,
    );

    act(() => {
      vi.advanceTimersByTime(60_000);
    });

    expect(screen.queryByRole("link", { name: "دخول الغرفة" })).toBeNull();
  });
});

afterEach(() => {
  vi.useRealTimers();
});
