import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SessionOwners } from "./SessionOwners";
import type { ClassSession } from "@/lib/class-sessions";

/*
| الكورسُ ومَن يُدرّسه — حقلانِ كانا يصلانِ من الخادمِ ولا يُرسمانِ في أيِّ مكان.
|
| ⚠️ والحالةُ التي تستحقُّ اختباراً هي **الغياب**. `teacher_name` يُرسَلُ بـ
| `whenLoaded`، فتقويمُ المدرّسِ نفسِه يصلُه بلا اسم؛ ومكوّنٌ يرسمُ فاصلاً أو
| وسماً فارغاً عندَ الغيابِ يترُكُ ارتفاعاً بلا محتوى في كلِّ صفٍّ من صفوفِه —
| وهو أثرٌ لا تراهُ لقطةُ شاشةٍ ولا يُسقِطُ اختباراً يسألُ عن الحاضرِ وحدَه.
*/
function session(extra: Partial<ClassSession> = {}): ClassSession {
  return {
    uuid: "s-1",
    title: "رياضيات — الدوالّ",
    type: "group",
    type_label: "جماعية",
    status: "scheduled",
    status_label: "مجدولة",
    room_closed: false,
    starts_at: "2026-09-11T17:00:00Z",
    ends_at: "2026-09-11T18:00:00Z",
    duration_minutes: 60,
    timezone: "Asia/Qatar",
    seats: { total: 6, taken: 3, available: 3 },
    my_booking: null,
    recording: null,
    ...extra,
  } as ClassSession;
}

describe("SessionOwners", () => {
  it("names the course and the teacher", () => {
    render(
      <SessionOwners
        session={session({
          course: { uuid: "c-1", title: "أساسيّات التفاضل" },
          teacher_name: "سارة العتيبي",
        })}
      />,
    );

    expect(screen.getByText("أساسيّات التفاضل")).toBeTruthy();
    expect(screen.getByText("سارة العتيبي")).toBeTruthy();
  });

  it("renders one of them alone without a dangling separator", () => {
    const { container } = render(
      <SessionOwners session={session({ course: { uuid: "c-1", title: "أساسيّات التفاضل" } })} />,
    );

    expect(container.textContent).toBe("أساسيّات التفاضل");
  });

  it("renders NOTHING when the payload carried neither", () => {
    // The teacher's own calendar: `whenLoaded` sends no name, and the course is
    // absent on a session attached to a subject. An empty paragraph here is a
    // gap under every title in the list.
    const { container } = render(<SessionOwners session={session()} />);

    expect(container.firstChild).toBeNull();
  });
});
