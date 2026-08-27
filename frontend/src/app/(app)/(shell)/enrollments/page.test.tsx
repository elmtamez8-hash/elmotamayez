import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import EnrollmentsPage from "./page";

/*
| «تعلّمي» — الجاري والمكتمل مفصولان، والفصلُ بالحالةِ لا بالنسبة.
|
| ⚠️ المجموعةُ المكتملةُ فرعٌ لا يراه أحدٌ على قاعدةِ عرضٍ فيها كورسٌ واحدٌ جارٍ،
| وهذا وحدَه سببٌ كافٍ لاختبارِها هنا. أمّا الفصلُ نفسُه فبـ`status`: بلوغُ
| `progress_pct` مئةً وكونُ التسجيلِ `completed` واقعتان مختلفتان — الثانيةُ
| يكتبُها `CourseCompleted`، والمقامُ يتحرّكُ تحتَ الأولى (تسجيلٌ يُؤرشَف، عنصرٌ
| يُنشَر) وهي عائلةُ العيبِ التي وُجِدت `ResyncCourseProgress` لأجلِها.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

vi.mock("@/lib/conversations", () => ({
  conversations: { start: vi.fn() },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

function enrollment(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "e-1",
    course_uuid: "c-1",
    course_title: "الرياضيات",
    workspace_uuid: "w-1",
    teacher_name: "أ. سامي",
    source: "manual",
    status: "active",
    progress_pct: 40,
    enrolled_at: "2026-08-01T09:00:00+00:00",
    completed_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("EnrollmentsPage", () => {
  it("separates what is running from what is finished", async () => {
    get.mockResolvedValue({
      data: [
        enrollment(),
        enrollment({ uuid: "e-2", course_uuid: "c-2", course_title: "الفيزياء", status: "completed", progress_pct: 100 }),
      ],
    });

    render(<EnrollmentsPage />);

    expect(await screen.findByText("جارية")).toBeTruthy();
    expect(screen.getByText("مكتملة")).toBeTruthy();
  });

  it("groups by the status, never by the percentage", async () => {
    /*
     | ⚠️ ١٠٠٪ وهي ما زالت `active` — وهي حالةٌ حقيقيّة، لا حالةٌ مصطنعةٌ للاختبار:
     | الشهادةُ تصدرُ عن حدثٍ منفصل، وقد يبلغُ الطالبُ المئةَ قبلَ أن يفرغَ
     | `CourseCompleted` من عملِه. قراءةُ الشريطِ بدلَ الحالةِ تُصنِّفُها «مكتملة»
     | وتعرضُ عليه «راجعِ الكورس» عن كورسٍ لم يُختَمْ بعد.
     */
    get.mockResolvedValue({
      data: [enrollment({ progress_pct: 100, status: "active" })],
    });

    render(<EnrollmentsPage />);

    expect(await screen.findByText("جارية")).toBeTruthy();
    expect(screen.queryByText("مكتملة")).toBeNull();
    expect(screen.getByRole("link", { name: /تابعِ التعلّم/ })).toBeTruthy();
  });

  it("names the teacher on the card, not only inside the chat button", async () => {
    // The button is absent whenever `workspace_uuid` is — which is exactly the
    // row that would then carry no teacher's name anywhere on it.
    get.mockResolvedValue({ data: [enrollment({ workspace_uuid: null })] });

    render(<EnrollmentsPage />);

    expect(await screen.findByText(/عند أ\. سامي/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: /راسل/ })).toBeNull();
  });

  it("offers a first step rather than «continue» at zero", async () => {
    get.mockResolvedValue({ data: [enrollment({ progress_pct: 0 })] });

    render(<EnrollmentsPage />);

    expect(await screen.findByRole("link", { name: /ابدأ الآن/ })).toBeTruthy();
  });

  it("reports the progress to a screen reader as a value out of a maximum", async () => {
    get.mockResolvedValue({ data: [enrollment({ progress_pct: 40 })] });

    render(<EnrollmentsPage />);

    const bar = await screen.findByRole("progressbar");

    // ⚠️ `aria-valuemax` is the half that gets dropped when the markup is
    // hand-rolled per screen — «40» with nothing to compare it to.
    expect(bar.getAttribute("aria-valuenow")).toBe("40");
    expect(bar.getAttribute("aria-valuemax")).toBe("100");
  });
});
