import { act, render, screen } from "@testing-library/react";
import { Suspense } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import SessionPage from "../page";
import SessionRoomPage from "./page";

/*
| ⛔ A STUDENT WHO IS EARLY IS NOT A STUDENT WHO IS REFUSED.
|
| The join refusal is uniform (FR-015) and reads «تأكّد من حجز مقعدك», so a student
| who pressed «دخول» before the teacher opened the room was sent hunting a booking
| that was fine, on a page that never tried again. And the session page read
| `join_open` once at fetch, so opening it twenty minutes early meant a button that
| never appeared without a reload.
|
| ⚠️ FAKE TIMERS, SO NO `waitFor` — it polls on the timers it would be waiting for.
| `advanceTimersByTimeAsync` flushes the promises as it goes.
*/

const get = vi.fn();
const post = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string, body: unknown) => post(path, body),
  },
}));

// Rendered only once a ticket exists; kept out of jsdom.
vi.mock("@/components/sessions/BroadcastStage", () => ({ BroadcastStage: () => null }));
vi.mock("@/components/sessions/PresenceLoop", () => ({ PresenceLoop: () => null }));
vi.mock("@/components/community/SessionChat", () => ({ SessionChat: () => null }));

function session(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "s-1",
    title: "حصّة الفيزياء",
    status: "scheduled",
    status_label: "مجدولة",
    starts_at: new Date().toISOString(),
    ends_at: new Date().toISOString(),
    timezone: "Asia/Qatar",
    duration_minutes: 60,
    room_closed: false,
    room_opened: false,
    join_open: true,
    seconds_until_join_open: 0,
    content_locked: null,
    ...overrides,
  };
}

const joinCalls = () => post.mock.calls.filter(([path]) => path === "/class-sessions/s-1/join").length;

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  get.mockImplementation(() => Promise.reject(new Error("unexpected read")));
});

afterEach(() => {
  vi.useRealTimers();
});

async function renderRoom() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <SessionRoomPage params={Promise.resolve({ uuid: "s-1" })} />
      </Suspense>,
    );
  });
  await act(() => vi.advanceTimersByTimeAsync(0));
}

describe("the room before the teacher opens it", () => {
  it("says the room is not open yet, and knocks again on its own", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1" ? Promise.resolve(session()) : Promise.reject(new Error("403")),
    );
    post.mockImplementation(() => Promise.reject(new Error("403")));

    await renderRoom();

    expect(screen.getByText("لم يفتح المدرّس الغرفة بعد")).toBeTruthy();
    expect(screen.queryByText("تعذّر الدخول")).toBeNull();
    expect(joinCalls()).toBe(1);

    await act(() => vi.advanceTimersByTimeAsync(20_000));

    expect(joinCalls()).toBe(2);
  });

  it("stops knocking once it is let in", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1" ? Promise.resolve(session()) : Promise.reject(new Error("403")),
    );
    post.mockImplementationOnce(() => Promise.reject(new Error("403"))).mockImplementation(() =>
      Promise.resolve({ role: "participant", token: "t", room_url: "wss://x", expires_at: "" }),
    );

    await renderRoom();
    await act(() => vi.advanceTimersByTimeAsync(20_000));
    expect(joinCalls()).toBe(2);

    await act(() => vi.advanceTimersByTimeAsync(60_000));
    expect(joinCalls()).toBe(2);
  });

  it("keeps the ordinary refusal when the room IS open (the refusal is about something else)", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: true }))
        : Promise.reject(new Error("403")),
    );
    post.mockImplementation(() => Promise.reject(new Error("403")));

    await renderRoom();

    expect(screen.getByText("تعذّر الدخول")).toBeTruthy();
    expect(screen.queryByText("لم يفتح المدرّس الغرفة بعد")).toBeNull();

    await act(() => vi.advanceTimersByTimeAsync(60_000));
    expect(joinCalls()).toBe(1);
  });
});

describe("the session page opened early", () => {
  it("shows «دخول الغرفة» when the window opens, without a reload", async () => {
    let calls = 0;
    get.mockImplementation((path: string) => {
      if (path !== "/class-sessions/s-1") return Promise.reject(new Error("403"));
      calls += 1;

      return Promise.resolve(
        calls === 1 ? session({ join_open: false, seconds_until_join_open: 600 }) : session(),
      );
    });

    await act(async () => {
      render(
        <Suspense fallback={null}>
          <SessionPage params={Promise.resolve({ uuid: "s-1" })} />
        </Suspense>,
      );
    });
    await act(() => vi.advanceTimersByTimeAsync(0));

    expect(screen.queryByText("دخول الغرفة")).toBeNull();

    await act(() => vi.advanceTimersByTimeAsync(601_000));

    expect(screen.getByText("دخول الغرفة")).toBeTruthy();
  });
});
