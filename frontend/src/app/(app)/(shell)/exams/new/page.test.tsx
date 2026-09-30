import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| ⛔ «اختبار جديد» posted no course at all (2026-09-30), and a CONFINED assistant
| may not set a paper for «all my students» — so they could not create an exam.
| The page now offers the reader's courses (the server narrows the list), sends
| the pick as a uuid, and requires it from a confined assistant only.
*/
const get = vi.fn();
const post = vi.fn();
const push = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string, body: unknown) => post(path, body),
  },
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push, back: vi.fn() }) }));

let mockUser: Record<string, unknown> = {};

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

const { default: NewExamPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = {};
  post.mockResolvedValue({ uuid: "e-1" });
  get.mockImplementation((path: string) =>
    Promise.resolve(
      path === "/courses?per_page=200"
        ? { data: [{ uuid: "c-near", title: "فيزياء ٣" }] }
        : { data: [] },
    ),
  );
});

async function openPage() {
  await act(async () => {
    render(<NewExamPage />);
  });
}

async function submitWith(title: string) {
  fireEvent.change(screen.getByLabelText(/عنوان الاختبار/), { target: { value: title } });
  await act(async () => {
    fireEvent.submit(screen.getByRole("button", { name: "أنشئ الاختبار" }).closest("form") as HTMLFormElement);
  });
}

function courseSelect(): HTMLSelectElement {
  return screen.getByLabelText(/^الكورس/) as HTMLSelectElement;
}

describe("the new exam form's course", () => {
  it("offers the reader's courses from the server's own list", async () => {
    await openPage();

    expect(get).toHaveBeenCalledWith("/courses?per_page=200");
    expect(screen.getByRole("option", { name: "فيزياء ٣" })).toBeTruthy();
  });

  it("lets a teacher set a paper for all their students — `course: null`", async () => {
    await openPage();

    expect(screen.getByRole("option", { name: "كل طلابي" })).toBeTruthy();
    expect(courseSelect().required).toBe(false);

    await submitWith("اختبار عام");

    expect(post).toHaveBeenCalledWith("/exams", expect.objectContaining({ course: null }));
  });

  it("sends the picked course as its uuid", async () => {
    await openPage();

    fireEvent.change(courseSelect(), { target: { value: "c-near" } });
    await submitWith("اختبار الفيزياء");

    expect(post).toHaveBeenCalledWith("/exams", expect.objectContaining({ course: "c-near" }));
  });

  it("requires a course from a confined assistant and never offers «كل طلابي»", async () => {
    mockUser = { is_confined_assistant: true };
    await openPage();

    expect(screen.queryByRole("option", { name: "كل طلابي" })).toBeNull();
    expect(courseSelect().required).toBe(true);

    fireEvent.change(courseSelect(), { target: { value: "c-near" } });
    await submitWith("اختبار المساعد");

    expect(post).toHaveBeenCalledWith("/exams", expect.objectContaining({ course: "c-near" }));
  });
});
