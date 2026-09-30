import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/*
| «اختبارات الكورس» on a course's page opened this list unfiltered — every
| course's papers mixed under a button that says «of the course». It carries
| `?course=` now, and the SERVER filters (`ExamController::index`).
*/
const get = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path) },
}));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: { permissions: ["exams.view", "exams.create"] } }) }));

const { default: ManageExamsPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  get.mockResolvedValue({ data: [] });
});

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

async function open() {
  await act(async () => {
    render(<ManageExamsPage />);
  });
}

describe("the exams list, narrowed to one course", () => {
  it("asks the server for that course's papers only — in the FIRST request", async () => {
    window.history.replaceState(null, "", "/manage/exams?course=c-1");
    await open();

    expect(get.mock.calls.map(([path]) => path)).toEqual(["/exams?course=c-1"]);
    expect(screen.getByText("اختبارات كورس واحد فقط")).toBeTruthy();
    expect(screen.getByText("لا اختبارات في هذا الكورس بعد")).toBeTruthy();
  });

  it("goes back to every paper on «اعرض كل الاختبارات»", async () => {
    window.history.replaceState(null, "", "/manage/exams?course=c-1");
    await open();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اعرض كل الاختبارات" }));
    });

    expect(get).toHaveBeenLastCalledWith("/exams");
    expect(window.location.search).toBe("");
    expect(screen.queryByText("اختبارات كورس واحد فقط")).toBeNull();
  });

  it("asks for the whole list with no filter in the address", async () => {
    await open();

    expect(get.mock.calls.map(([path]) => path)).toEqual(["/exams"]);
    expect(screen.queryByText("اختبارات كورس واحد فقط")).toBeNull();
  });
});
