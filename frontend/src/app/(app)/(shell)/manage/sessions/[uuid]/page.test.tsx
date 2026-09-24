import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

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
  it("is printed in Arabic, not as an IANA name", async () => {
    show.mockResolvedValue(session());
    await openPage();

    expect(screen.getByText("توقيت قطر")).toBeTruthy();
    expect(screen.queryByText("Asia/Qatar")).toBeNull();
  });
});
