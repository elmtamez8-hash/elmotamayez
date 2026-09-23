import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { SessionsTab } from "./SessionsTab";
import type { ClassSession } from "@/lib/class-sessions";
import { ApiError } from "@/lib/api";

const post = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { post: (path: string, body: unknown) => post(path, body) },
}));

/*
| ⛔ **قائمةٌ فارغةٌ لها سببانِ، وواحدٌ منهما ليسَ ذنبَ المدرّس.**
|
| التبويبُ يُرسَمُ من `has_sessions` — «هل لهذه المادّةِ حصّةٌ أصلاً؟» — والقائمةُ
| تحتَه تُضيَّقُ إلى مجموعاتِ القارئ. فالطالبُ الذي لم يُسنَدْ بعدُ إلى مجموعةٍ
| في كورسٍ جدولُه ممتلئٌ كانَ يقرأُ «لا حصص في هذه المادّة بعد — حين يجدول
| مدرّسك حصّة ستظهر هنا»: جملةٌ كاذبةٌ تتّهمُ مدرّساً جدولَها كلَّها.
|
| **والشقّانِ ضدّانِ عن قصد**: شقٌّ يُثبِتُ أنَّ السببَ يُقالُ حينَ يكونُ، وشقٌّ
| يُثبِتُ أنَّ الطالبَ المُسنَدَ لا يُقالُ له إنّه غيرُ مُسنَد. شقٌّ واحدٌ يمرُّ
| على بناءٍ يطبعُ الجملةَ لكلِّ أحد.
*/

const REASON = "لم تُسنَد إلى مجموعة بعد — انضمّ إلى مجموعة مفتوحة، أو تُسنِدك الإدارة.";

function session(uuid: string): ClassSession {
  return {
    uuid,
    title: "حصة المعادلات",
    starts_at: "2026-10-01T10:00:00Z",
    ends_at: "2026-10-01T11:00:00Z",
    seats: { total: 6, taken: 3, available: 3 },
  } as ClassSession;
}

describe("SessionsTab", () => {
  it("names the placement as the reason an empty list is empty", () => {
    render(<SessionsTab sessions={[]} unplacedReason={REASON} />);

    expect(screen.getByText(/بعد إسنادك إلى مجموعة/)).toBeTruthy();
    expect(screen.getByText(new RegExp("انضمّ إلى مجموعة مفتوحة"))).toBeTruthy();
    // والجملةُ الكاذبةُ غائبة.
    expect(screen.queryByText(/حين يجدول مدرّسك حصّة/)).toBeNull();
  });

  it("keeps the plain empty state for a student who IS placed", () => {
    render(<SessionsTab sessions={[]} unplacedReason={null} />);

    expect(screen.getByText(/حين يجدول مدرّسك حصّة/)).toBeTruthy();
    expect(screen.queryByText(/بعد إسنادك إلى مجموعة/)).toBeNull();
  });

  it("says nothing about placement when there are sessions to draw", () => {
    render(<SessionsTab sessions={[session("s-1")]} unplacedReason={REASON} />);

    expect(screen.queryByText(/بعد إسنادك إلى مجموعة/)).toBeNull();
    expect(screen.getByText("القادمة")).toBeTruthy();
  });
});

/*
| ⛔ «احجز» — spec 036: a session package gives CREDIT and the student books by the
| ordinary path. That path had no button, so the credit could not be spent.
*/
describe("the student's own booking", () => {
  // Far in the future so it lands among the upcoming ones.
  const upcoming = (overrides: Partial<ClassSession> = {}): ClassSession =>
    ({
      ...session("s-9"),
      starts_at: "2099-01-01T10:00:00Z",
      ends_at: "2099-01-01T11:00:00Z",
      status: "scheduled",
      my_booking: null,
      ...overrides,
    }) as ClassSession;

  beforeEach(() => {
    post.mockReset();
  });

  it("books a seat and says so", async () => {
    post.mockResolvedValue({ uuid: "b-1" });

    render(<SessionsTab sessions={[upcoming()]} />);
    fireEvent.click(screen.getByRole("button", { name: "احجز" }));

    expect(await screen.findByText("محجوز")).toBeTruthy();
    expect(post).toHaveBeenCalledWith("/class-sessions/s-9/book", {});
  });

  it("shows the server's own reason, not the generic 409 sentence", async () => {
    post.mockRejectedValue(
      new ApiError("رصيدك محجوزٌ لحصصٍ أخرى (1 حصة).", 409, { message: "رصيدك محجوزٌ لحصصٍ أخرى (1 حصة).", code: "booking_refused" }),
    );

    render(<SessionsTab sessions={[upcoming()]} />);
    fireEvent.click(screen.getByRole("button", { name: "احجز" }));

    expect(await screen.findByText(/رصيدك محجوزٌ لحصصٍ أخرى/)).toBeTruthy();
    expect(screen.queryByText(/تغيّرت الحالة/)).toBeNull();
  });

  it("offers nothing to book on a seat already held, a full session, or the past", () => {
    render(
      <SessionsTab
        sessions={[
          upcoming({ uuid: "held", my_booking: { uuid: "b", status: "booked", status_label: "محجوز", may_cancel_until: "" } }),
          upcoming({ uuid: "full", seats: { total: 6, taken: 6, available: 0 } }),
          upcoming({ uuid: "old", starts_at: "2000-01-01T10:00:00Z", ends_at: "2000-01-01T11:00:00Z" }),
        ]}
      />,
    );

    expect(screen.queryByRole("button", { name: "احجز" })).toBeNull();
    expect(screen.getByText("محجوز")).toBeTruthy();
  });

  /*
   | ⚠️ `DELETE /bookings/{uuid}` had no caller: a held seat showed «محجوز» and
   | nothing else, so a student who could not come had no way to say so.
   */
  it("offers a way out of a held seat, and never calls a given-up seat «محجوز»", () => {
    render(
      <SessionsTab
        sessions={[
          upcoming({
            uuid: "held",
            timezone: "Asia/Qatar",
            my_booking: { uuid: "b", status: "booked", status_label: "محجوز", may_cancel_until: "2098-12-31T10:00:00Z" },
          }),
          upcoming({
            uuid: "gone",
            my_booking: { uuid: "c", status: "cancelled_in_window", status_label: "أُلغي في المهلة", may_cancel_until: "" },
          }),
        ]}
      />,
    );

    expect(screen.getAllByRole("button", { name: "إلغاء الحجز" })).toHaveLength(1);
    expect(screen.getAllByText("محجوز")).toHaveLength(1);
    expect(screen.getByText("أُلغي في المهلة")).toBeTruthy();
  });
});
