import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import GradePaperPage from "./page";

/*
| An empty mark box is not a zero.
|
| `Number(points[id] ?? 0)` sent 0 for a box the grader had not filled, so
| pressing «اعتمد» early recorded a zero on the student's essay and the paper
| then read «مُصحَّح».
*/

const paper = vi.fn();
const grade = vi.fn();

vi.mock("next/navigation", () => ({
  useParams: () => ({ uuid: "att-1" }),
  useRouter: () => ({ refresh: vi.fn() }),
}));

vi.mock("@/lib/grading", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/grading")>()),
  grading: {
    paper: (uuid: string) => paper(uuid),
    grade: (...args: unknown[]) => grade(...args),
    revise: vi.fn(),
    queue: vi.fn(),
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  grade.mockResolvedValue({ data: {} });
  paper.mockResolvedValue({
    data: {
      uuid: "att-1",
      exam_title: "اختبار الفيزياء",
      submitted_at: "2026-09-20T10:00:00Z",
      status: "pending_grading",
      auto_score: 0,
      is_anonymous: false,
      student: { uuid: "s-1", name: "سارة" },
      answers: [
        {
          uuid: "ans-1",
          question: { uuid: "q-1", content: "اشرح قانون نيوتن الأول.", explanation: null },
          answer_text: "الجسم يبقى على حالته",
          points_possible: 5,
          points_awarded: 0,
          is_graded: false,
          graded_at: null,
          grading_version: 0,
          criteria: [],
          marks: [],
        },
      ],
    },
  });
});

async function open() {
  await act(async () => {
    render(<GradePaperPage />);
  });
}

describe("marking an essay", () => {
  it("refuses to record an empty box as zero", async () => {
    await open();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اعتمد الدرجة" }));
    });

    expect(grade).not.toHaveBeenCalled();
    expect(screen.getByText("أدخل الدرجة قبل اعتمادها.")).toBeDefined();
  });

  it("sends the mark the grader typed, including a deliberate zero", async () => {
    await open();

    fireEvent.change(screen.getByLabelText("الدرجة"), { target: { value: "0" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اعتمد الدرجة" }));
    });

    expect(grade).toHaveBeenCalledWith("ans-1", [{ criterion_id: null, points: 0, comment: null }]);
  });
});
