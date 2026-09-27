import { act, fireEvent, render, screen } from "@testing-library/react";
import { Suspense } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

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
// One beat on mount, so the line the beat feeds («مدة حضورك») can be asked about.
vi.mock("@/components/sessions/PresenceLoop", async () => {
  const { useEffect } = await import("react");
  return {
    PresenceLoop: ({ onUpdate }: { onUpdate?: (state: { stay_seconds: number; status: string; session_status: string }) => void }) => {
      useEffect(() => {
        onUpdate?.({ stay_seconds: 600, status: "present", session_status: "live" });
      }, [onUpdate]);
      return null;
    },
  };
});
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
const showCalls = () => get.mock.calls.filter(([path]) => path === "/class-sessions/s-1").length;

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
  it("says the room is not open yet, and watches the SESSION rather than re-posting the join", async () => {
    /*
    | ⛔ 2026-09-26: one device sent 228 joins in two hours, every one a 403,
    | because «not yet» was read from the refusal and the refusal was re-posted
    | every twenty seconds. The poll is a GET on the session now.
    */
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1" ? Promise.resolve(session()) : Promise.reject(new Error("403")),
    );
    post.mockImplementation(() => Promise.reject(new Error("403")));

    await renderRoom();

    expect(screen.getByText("لم يفتح المدرّس الغرفة بعد")).toBeTruthy();
    expect(screen.queryByText("تعذّر الدخول")).toBeNull();
    expect(joinCalls()).toBe(1);
    const readsBefore = showCalls();

    await act(() => vi.advanceTimersByTimeAsync(20_000));

    expect(joinCalls()).toBe(1);
    expect(showCalls()).toBe(readsBefore + 1);

    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));
    expect(joinCalls()).toBe(1);
  });

  it("knocks once when the host opens the room, and stops once it is let in", async () => {
    let opened = false;
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: opened }))
        : Promise.reject(new Error("403")),
    );
    post.mockImplementationOnce(() => Promise.reject(new Error("403"))).mockImplementation(() =>
      Promise.resolve({ role: "participant", token: "t", room_url: "wss://x", expires_at: "" }),
    );

    await renderRoom();
    await act(() => vi.advanceTimersByTimeAsync(20_000));
    expect(joinCalls()).toBe(1);

    opened = true;
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

describe("a refused or failing join", () => {
  /*
  | ⛔ A 4xx IS AN ANSWER. Real `ApiError`s here, not `Error("403")`: the page
  | decides retry-or-stop on the status, and a plain Error would pass through the
  | «unknown, so final» arm and prove nothing about the 403 arm.
  */
  const refusal = () =>
    new ApiError("لا يمكنك دخول هذه الحصة الآن.", 403, {
      message: "لا يمكنك دخول هذه الحصة الآن.",
      code: "session_not_joinable",
    });

  it("sends exactly ONE join on a 403, shows the server's reason, and offers a manual retry", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: true, status: "live" }))
        : Promise.reject(new Error("unexpected read")),
    );
    post.mockImplementation(() => Promise.reject(refusal()));

    await renderRoom();

    expect(joinCalls()).toBe(1);
    expect(screen.getByText("تعذّر الدخول")).toBeTruthy();
    expect(screen.getByText(/لا يمكنك دخول هذه الحصة الآن\. تأكّد من حجز مقعدك/)).toBeTruthy();

    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));
    expect(joinCalls()).toBe(1);

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حاول مرة أخرى" }));
    });
    await act(() => vi.advanceTimersByTimeAsync(0));

    expect(joinCalls()).toBe(2);

    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));
    expect(joinCalls()).toBe(2);
  });

  it.each([401, 404, 409, 410, 422])("does not retry a %i on its own", async (status) => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: true, status: "live" }))
        : Promise.reject(new Error("unexpected read")),
    );
    post.mockImplementation(() => Promise.reject(new ApiError("مرفوض", status, null)));

    await renderRoom();
    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));

    expect(joinCalls()).toBe(1);
  });

  it("retries a 503 with backoff, gives up after five tries, and leaves the button", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: true, status: "live" }))
        : Promise.reject(new Error("unexpected read")),
    );
    post.mockImplementation(() =>
      Promise.reject(new ApiError("down", 503, { code: "broadcast_unavailable" })),
    );

    await renderRoom();

    expect(joinCalls()).toBe(1);
    expect(screen.getByText(/نعيد المحاولة تلقائياً/)).toBeTruthy();

    // 2s + 4s + 8s + 16s between the five tries.
    await act(() => vi.advanceTimersByTimeAsync(30_000));
    expect(joinCalls()).toBe(5);

    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));
    expect(joinCalls()).toBe(5);
    expect(screen.getByText("تعذّر الدخول")).toBeTruthy();
    expect(screen.getByRole("button", { name: "حاول مرة أخرى" })).toBeTruthy();
    // Never the developer string.
    expect(screen.queryByText("down")).toBeNull();
  });

  it("gets in on a later try after a network drop", async () => {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ room_opened: true, status: "live" }))
        : Promise.reject(new Error("unexpected read")),
    );
    post
      .mockImplementationOnce(() => Promise.reject(new TypeError("Failed to fetch")))
      .mockImplementation(() =>
        Promise.resolve({ role: "participant", token: "t", room_url: "wss://x", expires_at: "", presence_interval_seconds: 30 }),
      );

    await renderRoom();
    await act(() => vi.advanceTimersByTimeAsync(2_000));

    expect(joinCalls()).toBe(2);
    expect(screen.queryByText("تعذّر الدخول")).toBeNull();

    await act(() => vi.advanceTimersByTimeAsync(10 * 60_000));
    expect(joinCalls()).toBe(2);
  });
});

describe("a cancelled session", () => {
  it("says it was cancelled instead of the student sentence about a seat", async () => {
    /*
    | ⛔ `CancelClassSession` never stamps `room_closed_at`, so the host of a
    | cancelled lesson was told «تأكّد من حجز مقعدك» — a sentence written for a
    | student, about a booking the teacher never had.
    */
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ status: "cancelled", status_label: "ملغاة", join_open: false }))
        : Promise.reject(new Error("403")),
    );
    post.mockImplementation(() => Promise.reject(new Error("403")));

    await renderRoom();

    expect(screen.getByText("هذه الحصة ملغاة")).toBeTruthy();
    expect(screen.queryByText("تعذّر الدخول")).toBeNull();

    await act(() => vi.advanceTimersByTimeAsync(60_000));
    expect(joinCalls()).toBe(1);
  });
});

describe("«مدة حضورك» in the room", () => {
  /*
  | The host's beat keeps a register row (delivery is judged from it), and the
  | room printed its stay to the TEACHER as «مدة حضورك» — a student's line about
  | a lesson they were giving, not attending.
  */
  async function enterAs(role: "host" | "participant") {
    get.mockImplementation((path: string) =>
      path === "/class-sessions/s-1"
        ? Promise.resolve(session({ status: "live", room_opened: true }))
        : Promise.reject(new Error("403")),
    );
    post.mockImplementation(() =>
      Promise.resolve({ role, token: "t", room_url: "wss://x", expires_at: "", presence_interval_seconds: 30 }),
    );

    await renderRoom();
    await act(() => vi.advanceTimersByTimeAsync(0));
  }

  it("shows the student their stay", async () => {
    await enterAs("participant");

    expect(screen.queryByText(/مدة حضورك/)).not.toBeNull();
  });

  it("never shows it to the host", async () => {
    await enterAs("host");

    expect(screen.queryByText(/مدة حضورك/)).toBeNull();
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
