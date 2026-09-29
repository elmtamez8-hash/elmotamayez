import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
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
    list: (params?: unknown) => list(params),
    submissions: (uuid: string) => submissions(uuid),
    grade: (...args: unknown[]) => grade(...args),
    openFile: (...args: unknown[]) => openFile(...args),
    publish: vi.fn(),
  },
}));

// The extension form above the list reads the teacher's students; this file
// is about grading, so the picker is simply empty.
vi.mock("@/lib/use-teacher-students", () => ({
  useTeacherStudents: () => ({ canPick: true, students: [], failed: false }),
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

    fireEvent.change(await screen.findByLabelText("الدرجة (١٠)"), { target: { value: "7" } });

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

describe("the assignment row", () => {
  // «من 10 درجة» shipped: a Latin digit and the singular where 3–10 takes the plural.
  it("counts the full mark in Arabic, with the noun agreeing", async () => {
    await act(async () => {
      render(<ManageAssignmentsPage />);
    });

    expect(await screen.findByText(/من ١٠ درجات/)).toBeTruthy();
    expect(screen.queryByText(/10/)).toBeNull();
  });
});

/*
| ⛔ THE LIST IS PAGED AT 30, SO EVERY FILTER AND EVERY COUNT IS THE SERVER'S.
| The chips used to narrow page one in the browser and the search did not exist;
| a teacher with a term of homework could not reach assignment thirty-one.
*/
const ROW = {
  uuid: "as-1",
  title: "واجب الفصل الثالث",
  points: 10,
  due_at: null,
  status: "published",
  submitted_count: 1,
  pending_count: 1,
};

type ListParams = { page?: number; q?: string; status?: string };

/** A fake server: one published row on page one, one draft on page two. */
function serveTwoPages() {
  list.mockImplementation(async (params: ListParams = {}) => {
    const all = [ROW, { ...ROW, uuid: "as-2", title: "واجب المسوّدة", status: "draft" }];
    const matching = all.filter(
      (row) =>
        (!params.status || row.status === params.status) && (!params.q || row.title.includes(params.q)),
    );
    const page = params.page ?? 1;
    const perPage = !params.status && !params.q ? 1 : 30;

    return {
      data: matching.slice((page - 1) * perPage, page * perPage),
      meta: {
        total: matching.length,
        current_page: page,
        last_page: Math.max(1, Math.ceil(matching.length / perPage)),
        counts: { draft: 1, published: 1 },
      },
    };
  });
}

describe("the staff list, paged and filtered on the server", () => {
  it("sends the chip to the server and shows the server's counts", async () => {
    serveTwoPages();

    await act(async () => {
      render(<ManageAssignmentsPage />);
    });

    // «الكل ٢» is the sum of the server's counts, not the one row on screen.
    expect(await screen.findByRole("button", { name: "الكل ٢" })).toBeTruthy();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "المسوّدات ١" }));
    });

    expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, status: "draft" }));
    expect(await screen.findByText("واجب المسوّدة")).toBeTruthy();
    expect(screen.queryByText("واجب الفصل الثالث")).toBeNull();
  });

  it("searches on the server after the typing stops, and a search with no match can be cleared", async () => {
    serveTwoPages();

    await act(async () => {
      render(<ManageAssignmentsPage />);
    });

    fireEvent.change(await screen.findByLabelText("ابحث في واجباتك"), { target: { value: "غير موجود" } });

    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ q: "غير موجود" })),
    );
    expect(await screen.findByText("لا واجب يطابق")).toBeTruthy();

    await act(async () => {
      // The empty state's action (by its visible text): the search box's own ✕
      // shares the accessible name but clears the text alone.
      fireEvent.click(screen.getByText("مسح البحث", { selector: "button" }));
    });

    expect(await screen.findByText("واجب الفصل الثالث")).toBeTruthy();
    expect((screen.getByLabelText("ابحث في واجباتك") as HTMLInputElement).value).toBe("");
  });

  it("reaches the next page with «عرض المزيد», appended under the first", async () => {
    serveTwoPages();

    await act(async () => {
      render(<ManageAssignmentsPage />);
    });

    await act(async () => {
      fireEvent.click(await screen.findByRole("button", { name: "عرض المزيد" }));
    });

    expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }));
    expect(await screen.findByText("واجب المسوّدة")).toBeTruthy();
    expect(screen.getByText("واجب الفصل الثالث")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "عرض المزيد" })).toBeNull();
  });
});
