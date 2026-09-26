import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setStoredViewerTimeZone } from "@/lib/viewer-time-zone";

/*
| The teacher's page for one session (reported on production 2026-09-24).
|
| ⛔ «إلغاء الحصة» cancelled on ONE press — irreversible, and it notifies every
| seat holder at once. ⛔ A cancelled session still offered «دخول الغرفة»,
| because cancelling never stamps `room_closed_at`. ⛔ The timezone printed raw
| as «Asia/Qatar».
*/
const show = vi.fn();
const cancel = vi.fn();

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    show: (uuid: string) => show(uuid),
    cancel: (uuid: string) => cancel(uuid),
    update: vi.fn(),
  },
  attendance: { list: () => Promise.resolve({ data: [] }) },
  recordingLabel: (status: string) => status,
}));

vi.mock("@/components/sessions/AttendanceSheet", () => ({ AttendanceSheet: () => null }));
vi.mock("@/components/sessions/UnlockExemptions", () => ({
  UnlockExemptions: () => <p>بطاقة الاستثناءات</p>,
}));

let mockUser: { permissions: string[] } = { permissions: [] };

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

const { default: ManageSessionPage } = await import("./page");

function session(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "s-1",
    title: "حصّة الفيزياء",
    status: "scheduled",
    status_label: "مجدولة",
    type_label: "جماعية",
    starts_at: "2026-10-01T15:00:00Z",
    timezone: "Asia/Qatar",
    duration_minutes: 60,
    room_closed: false,
    seats: { total: 10, taken: 2, available: 8 },
    recording: null,
    ...overrides,
  };
}

async function openPage() {
  await act(async () => {
    render(<ManageSessionPage params={Promise.resolve({ uuid: "s-1" })} />);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { permissions: [] };
});

describe("cancelling a session", () => {
  it("asks first, and cancels nothing on the first press", async () => {
    show.mockResolvedValue(session());
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: "إلغاء الحصة" }));

    expect(
      screen.getByText(
        "لا يمكن التراجع عن الإلغاء، وسيصل إشعار به إلى كل من حجز مقعداً في هذه الحصة.",
      ),
    ).toBeTruthy();
    expect(cancel).not.toHaveBeenCalled();
  });

  it("backing out cancels nothing", async () => {
    show.mockResolvedValue(session());
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: "إلغاء الحصة" }));
    fireEvent.click(screen.getByRole("button", { name: "إلغاء" }));

    expect(cancel).not.toHaveBeenCalled();
  });

  it("confirming cancels exactly once", async () => {
    show.mockResolvedValue(session());
    cancel.mockResolvedValue(session({ status: "cancelled", status_label: "ملغاة" }));
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: "إلغاء الحصة" }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "ألغِ الحصة" }));
    });

    expect(cancel).toHaveBeenCalledTimes(1);
    expect(cancel).toHaveBeenCalledWith("s-1");
  });
});

describe("the room button", () => {
  it("is offered on a scheduled session", async () => {
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.getByText("دخول الغرفة")).toBeTruthy();
  });

  it.each(["cancelled", "completed"])("is not offered on a %s session", async (status) => {
    // `room_closed` is false on purpose: a cancel never stamps it.
    show.mockResolvedValue(session({ status, status_label: status, room_closed: false }));
    await openPage();

    expect(screen.queryByText("دخول الغرفة")).toBeNull();
  });
});

describe("the timezone", () => {
  /*
  | The VIEWER's zone (2026-09-25) — the account's stored one here, pinned so the
  | case does not depend on the machine running it.
  */
  afterEach(() => setStoredViewerTimeZone(null));

  it("is printed in Arabic, not as an IANA name", async () => {
    setStoredViewerTimeZone("Asia/Qatar");
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.getByText("توقيت قطر")).toBeTruthy();
    expect(screen.queryByText("Asia/Qatar")).toBeNull();
  });

  it("is the viewer's own, not the platform's the payload names", async () => {
    setStoredViewerTimeZone("Africa/Cairo");
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.getByText("توقيت مصر")).toBeTruthy();
    expect(screen.queryByText("توقيت قطر")).toBeNull();
  });
});

/*
| FR-040's only door: `POST /manage/class-sessions/{uuid}/unlock-exemptions` had
| no caller in `frontend/src` until this card. It answers to the rule's own
| permission, and only while the session can still be booked or joined.
*/
describe("the unlock exemption card", () => {
  it("is shown to a teacher who holds the rule's permission", async () => {
    mockUser = { permissions: ["unlock_rules.manage"] };
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.getByText("استثناء من شرط الفتح")).toBeTruthy();
    expect(screen.getByText("بطاقة الاستثناءات")).toBeTruthy();
  });

  it("is hidden from an account without it", async () => {
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.queryByText("استثناء من شرط الفتح")).toBeNull();
  });

  it("is hidden on a session that is over", async () => {
    mockUser = { permissions: ["unlock_rules.manage"] };
    show.mockResolvedValue(session({ status: "completed", status_label: "منتهية" }));
    await openPage();

    expect(screen.queryByText("استثناء من شرط الفتح")).toBeNull();
  });
});
