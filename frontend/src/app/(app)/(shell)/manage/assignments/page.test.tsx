import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageAssignmentsPage from "./page";

/*
| An empty score box is not a zero.
|
| `Number(score)` with `score === ""` is 0, so «اعتمد الدرجة» on an untouched
| submission recorded a zero the teacher never gave.
*/

const list = vi.fn();
const submissions = vi.fn();
const grade = vi.fn();
const openFile = vi.fn();

vi.mock("@/lib/assignments", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/assignments")>()),
  assignments: {
    list: () => list(),
    submissions: (uuid: string) => submissions(uuid),
    grade: (...args: unknown[]) => grade(...args),
    openFile: (...args: unknown[]) => openFile(...args),
    publish: vi.fn(),
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  grade.mockResolvedValue({});
  list.mockResolvedValue({
    data: [
      {
        uuid: "as-1",
        title: "واجب الفصل الثالث",
        points: 10,
        due_at: null,
        status: "published",
        submitted_count: 1,
        pending_count: 1,
      },
    ],
  });
  submissions.mockResolvedValue({
    data: [
      {
        uuid: "sub-1",
        assignment: null,
        student: { uuid: "s-1", name: "سارة" },
        answer_text: "حلّي",
        has_file: false,
        score: null,
        feedback: null,
        graded_at: null,
        is_graded: false,
        late_penalty_applied_pct: null,
        state: "submitted",
      },
    ],
  });
});

async function openSubmissions() {
  await act(async () => {
    render(<ManageAssignmentsPage />);
  });

  await act(async () => {
    fireEvent.click(await screen.findByRole("button", { name: "التسليمات" }));
  });
}

describe("grading a submission", () => {
  it("refuses to record an empty score as zero", async () => {
    await openSubmissions();

    await act(async () => {
      fireEvent.click(await screen.findByRole("button", { name: "اعتمد الدرجة" }));
    });

    expect(grade).not.toHaveBeenCalled();
    expect(screen.getByText("أدخل الدرجة قبل اعتمادها.")).toBeDefined();
  });

  it("sends the score once one is typed", async () => {
    await openSubmissions();

    fireEvent.change(await screen.findByLabelText("الدرجة (10)"), { target: { value: "7" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اعتمد الدرجة" }));
    });

    expect(grade).toHaveBeenCalledWith("sub-1", 7, "");
  });
});

/*
| The handed-in file.
|
| The row said only `has_file`, so the teacher could see a worksheet had been
| handed in and had no way to read it.
*/
describe("the handed-in file", () => {
  function withFile(hasFile: boolean) {
    submissions.mockResolvedValue({
      data: [
        {
          uuid: "sub-1",
          assignment: null,
          student: { uuid: "s-1", name: "سارة" },
          answer_text: null,
          has_file: hasFile,
          file_url: hasFile ? "/api/v1/submissions/sub-1/file?signature=x" : undefined,
          file_name: hasFile ? "worksheet.pdf" : undefined,
          score: null,
          feedback: null,
          graded_at: null,
          is_graded: false,
          late_penalty_applied_pct: null,
          state: "on_time",
        },
      ],
    });
  }

  it("offers the file and fetches it through the authenticated helper", async () => {
    withFile(true);
    openFile.mockResolvedValue(undefined);

    await openSubmissions();

    await act(async () => {
      fireEvent.click(await screen.findByRole("button", { name: "نزّل الملف المرفق" }));
    });

    expect(openFile).toHaveBeenCalledWith("as-1", "sub-1");
  });

  it("says why when the file cannot be fetched", async () => {
    withFile(true);
    openFile.mockRejectedValue(new TypeError("Failed to fetch"));

    await openSubmissions();

    await act(async () => {
      fireEvent.click(await screen.findByRole("button", { name: "نزّل الملف المرفق" }));
    });

    // Never the raw «Failed to fetch»: the sentence comes from `userMessage()`.
    expect(screen.queryByText("Failed to fetch")).toBeNull();
    expect(screen.getByRole("alert")).toBeDefined();
  });

  it("offers nothing when no file was handed in", async () => {
    withFile(false);

    await openSubmissions();
    await screen.findByText("سارة");

    expect(screen.queryByRole("button", { name: "نزّل الملف المرفق" })).toBeNull();
  });
});
