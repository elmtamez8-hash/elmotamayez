import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import RescheduleQueuePage from "./page";

/*
| طابورُ المدرّس — وما يبيتُ عليه القرار.
|
| ⚠️ الموافقةُ **قد تفشل**، والجملةُ هي الميزة: النقلُ يمرُّ عبرَ الفعلِ نفسِه الذي
| يجدولُ الحصص، فموعدٌ يتعارضُ مع مجموعةٍ أخرى يُرفَضُ بجملةٍ يستطيعُ المدرّسُ أن
| يتصرّفَ بناءً عليها — و«تعذّر تنفيذ الإجراء» مكانَها تتركُه يضغطُ الزرَّ نفسَه
| إلى الأبد.
*/

const queue = vi.fn();
const approve = vi.fn();
const reject = vi.fn();

vi.mock("@/lib/reschedule-requests", () => ({
  rescheduleRequests: {
    queue: () => queue(),
    approve: (uuid: string) => approve(uuid),
    reject: (uuid: string, reason: string) => reject(uuid, reason),
  },
}));

const ROW = {
  uuid: "r-1",
  status: "pending" as const,
  from_starts_at: "2026-10-10T13:00:00Z",
  to_starts_at: "2026-10-11T15:00:00Z",
  student_reason: "عندي امتحان",
  decision_reason: null,
  decided_at: null,
  created_at: "2026-10-01T09:00:00Z",
  session: { uuid: "s-1", title: "حصة الفيزياء" },
  student: { uuid: "u-1", name: "سامي" },
};

beforeEach(() => {
  vi.clearAllMocks();
  queue.mockResolvedValue({ data: [ROW] });
  approve.mockResolvedValue({});
  reject.mockResolvedValue({});
});

async function open() {
  await act(async () => {
    render(<RescheduleQueuePage />);
  });
}

describe("the teacher's reschedule queue", () => {
  it("shows what is being given up as well as what is being asked for", async () => {
    await open();

    // «إلى الأحد ٦م» alone makes a teacher open their calendar to find the one
    // fact the decision turns on.
    expect(screen.getByText("سامي — حصة الفيزياء")).toBeTruthy();
    expect(screen.getByText("عندي امتحان")).toBeTruthy();

    const times = screen.getAllByText(/٢٠٢٦|2026|أكتوبر|October/);

    expect(times.length).toBeGreaterThanOrEqual(2);
  });

  it("refuses to send a rejection with no words in it", async () => {
    await open();

    fireEvent.click(screen.getByRole("button", { name: "رفض" }));

    const send = screen.getByRole("button", { name: "أرسل الرفض" }) as HTMLButtonElement;

    // A silent refusal is indistinguishable from a request still waiting, so it
    // is submitted again — which is this same queue, twice.
    expect(send.disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/سبب الرفض/), { target: { value: "الأحد مشغول" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أرسل الرفض" }));
    });

    expect(reject.mock.calls.at(-1)).toEqual(["r-1", "الأحد مشغول"]);
  });

  it("keeps the request in the queue and prints the clash when an approval is refused", async () => {
    approve.mockRejectedValue(
      new ApiError("الموعد يتعارض مع حصة أخرى لك.", 422, { message: "الموعد يتعارض مع حصة أخرى لك." }),
    );

    await open();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "وافِق وانقل الحصة" }));
    });

    expect(await screen.findByText("الموعد يتعارض مع حصة أخرى لك.")).toBeTruthy();
    // Still there to be answered differently — the row is not consumed by a
    // refusal, because nothing moved.
    expect(screen.getByRole("button", { name: "وافِق وانقل الحصة" })).toBeTruthy();
  });

  it("reloads after a decision, so a settled row does not sit in the queue", async () => {
    await open();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "وافِق وانقل الحصة" }));
    });

    expect(approve.mock.calls.at(-1)).toEqual(["r-1"]);
    expect(queue.mock.calls.length).toBe(2);
  });
});
