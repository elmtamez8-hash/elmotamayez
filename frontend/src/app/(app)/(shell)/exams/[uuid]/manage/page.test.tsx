import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| The assistant role holds `exams.create`/`exams.update` and not `exams.publish`,
| `exams.delete` or `questions.manage` (`RolePermissionMatrix`). Each of those was
| a control on this screen that the server answered 403 — the question picker
| included, whose very READ (`GET /manage/exams/{uuid}/items`) asks
| `manageQuestions`, so it drew an error box under a page that otherwise worked.
*/
const get = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/components/bank/ExamItemsPanel", () => ({ ExamItemsPanel: () => <p>أسئلة الاختبار من البنك</p> }));

const TEACHER = ["exams.view", "exams.create", "exams.update", "exams.publish", "exams.delete", "questions.manage"];
let mockUser: { permissions: string[] } = { permissions: TEACHER };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: ManageExamPage } = await import("./page");

const EXAM = {
  uuid: "e-1",
  course_id: 1,
  title: "اختبار الوحدة الأولى",
  description: "",
  duration_minutes: 30,
  passing_score: 60,
  max_attempts: 2,
  status: "draft",
  is_published: false,
};

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { permissions: TEACHER };
  get.mockResolvedValue(EXAM);
});

async function open() {
  await act(async () => {
    render(<ManageExamPage params={Promise.resolve({ uuid: "e-1" })} />);
  });
}

describe("an exam's own screen", () => {
  it("gives the teacher publish, delete and the question picker", async () => {
    await open();

    expect(screen.getByRole("button", { name: "انشر الاختبار" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "حذف" })).toBeTruthy();
    expect(screen.getByText("أسئلة الاختبار من البنك")).toBeTruthy();
  });

  it("draws none of the three for an assistant, and keeps the settings", async () => {
    mockUser = { permissions: ["exams.view", "exams.create", "exams.update"] };
    await open();

    expect(screen.queryByRole("button", { name: "انشر الاختبار" })).toBeNull();
    expect(screen.queryByRole("button", { name: "حذف" })).toBeNull();
    expect(screen.queryByText("أسئلة الاختبار من البنك")).toBeNull();
    expect(screen.getByText("أسئلة الاختبار يختارها المدرّس")).toBeTruthy();
    expect(screen.getByRole("button", { name: "إعدادات الاختبار" })).toBeTruthy();
    // One read, the exam — nothing that 403s.
    expect(get.mock.calls.map(([path]) => path)).toEqual(["/exams/e-1"]);
  });
});
