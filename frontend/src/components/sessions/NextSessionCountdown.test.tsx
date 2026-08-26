import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { NextSessionCountdown } from "./NextSessionCountdown";
import type { SessionBooking } from "@/lib/class-sessions";

/*
| العدُّ التنازليُّ، وزرٌّ كان يرفضُ دائماً.
|
| «دخول الغرفة» كان يُرسَمُ لحصّةٍ بعدَ ثلاثةِ أيّام، والضغطُ عليه يُجيبُ رفضَ البابِ
| الموحَّد: «تأكّد من حجز مقعدك» — عن مقعدٍ يملكُه الطالب، في حصّةٍ يشاهدُ عدَّها
| التنازليَّ أمامَه. والعدّادُ نفسُه يقفُ عند صفرٍ بلا فرعٍ له، فحصّةٌ بدأت تقرأُ
| «يبدأ بعد ٠ ساعة و٠ دقيقة و٠ ثانية» إلى الأبد.
*/

function bookingIn(): SessionBooking {
  return {
    uuid: "b-1",
    status: "booked",
    status_label: "محجوز",
    booked_at: "2026-08-26T10:00:00Z",
    cancelled_at: null,
    session: {
      uuid: "s-1",
      title: "رياضيات — الدوالّ",
      type: "group",
      type_label: "جماعية",
      status: "scheduled",
      status_label: "مجدولة",
      room_closed: false,
      starts_at: "2026-08-27T17:00:00Z",
      ends_at: "2026-08-27T18:00:00Z",
      duration_minutes: 60,
      timezone: "Asia/Qatar",
      seats: { total: 6, taken: 3, available: 3 },
      my_booking: null,
      recording: null,
    },
  } as SessionBooking;
}

describe("NextSessionCountdown", () => {
  it("offers no room link for a lesson that is still hours away", () => {
    render(<NextSessionCountdown booking={bookingIn()} secondsUntilStart={3 * 3600} />);

    expect(screen.queryByRole("link", { name: "دخول الغرفة" })).toBeNull();
    expect(screen.getByText(/يُفتح الدخول/)).toBeTruthy();
  });

  it("offers it once the door can actually open", () => {
    render(<NextSessionCountdown booking={bookingIn()} secondsUntilStart={5 * 60} />);

    expect(screen.getByRole("link", { name: "دخول الغرفة" })).toBeTruthy();
  });

  it("says the lesson has started instead of counting zeroes for ever", () => {
    render(<NextSessionCountdown booking={bookingIn()} secondsUntilStart={0} />);

    expect(screen.getByText("بدأت الآن")).toBeTruthy();
    expect(screen.queryByText(/يبدأ بعد/)).toBeNull();
  });

  it("does not announce itself once a second", () => {
    render(<NextSessionCountdown booking={bookingIn()} secondsUntilStart={3600} />);

    // A paragraph that changes every second carried `aria-live="polite"`, so a
    // screen reader re-read the whole sentence once a second for as long as the
    // page was open. `role="timer"` is what the value is.
    const timer = screen.getByRole("timer");

    expect(timer.getAttribute("aria-live")).toBeNull();
    expect(timer.getAttribute("aria-label")).toContain("موعد الحصة");
  });
});
