import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SessionsTab } from "./SessionsTab";
import type { ClassSession } from "@/lib/class-sessions";

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
