import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| «ظهور الكورس» — قرارُ المدرّس منذُ 2026-09-26 (قرارُ المالك): عامٌّ افتراضاً،
| وله أن يجعلَه خاصّاً ويعيدَه من هذه الشاشة.
*/
const get = vi.fn();
const put = vi.fn();
const push = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    put: (path: string, body: unknown) => put(path, body),
    post: vi.fn(),
  },
  fieldErrors: () => ({}),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, back: vi.fn() }),
}));

const { default: EditCoursePage } = await import("./page");

function course(visibility: string, canChange: boolean | "absent") {
  return {
    // «absent» is a read nobody stamped — the key is missing, not false.
    ...(canChange === "absent" ? {} : { can_change_visibility: canChange }),
    uuid: "c-1",
    title: "رياضيات",
    description: "",
    slug: "math",
    status: "published",
    visibility,
    currency: "QAR",
    is_sequential: true,
    is_free_enrollment: false,
    subject: { uuid: "s-1", label: "الرياضيات" },
    grade_level: null,
    course_type: "recorded",
    has_sessions: false,
    promo_video_id: null,
    promo_video_status: "none",
  };
}

async function openPage(visibility: string, canChange: boolean | "absent" = true) {
  get.mockImplementation((path: string) =>
    Promise.resolve(path === "/courses/c-1" ? course(visibility, canChange) : { data: [] }),
  );

  await act(async () => {
    render(<EditCoursePage params={Promise.resolve({ uuid: "c-1" })} />);
  });
}

async function save() {
  await act(async () => {
    // The form's own submit, past the browser's `required` check on pickers
    // this fixture leaves empty — what is measured is the payload.
    fireEvent.submit(screen.getByRole("button", { name: "احفظ التغييرات" }).closest("form") as HTMLFormElement);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  put.mockResolvedValue({});
});

describe("«ظهور الكورس» on the edit form", () => {
  it("shows the course's current choice", async () => {
    await openPage("public");

    expect((screen.getByLabelText(/عام — يظهر في السوق/) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText(/خاص — لا يصل إليه/) as HTMLInputElement).checked).toBe(false);
  });

  it("sends «private» when the teacher switches it", async () => {
    await openPage("public");

    fireEvent.click(screen.getByLabelText(/خاص — لا يصل إليه/));
    await save();

    expect(put).toHaveBeenCalledWith("/courses/c-1", expect.objectContaining({ visibility: "private" }));
  });

  it("sends «public» back when a private course is opened up", async () => {
    await openPage("private");

    fireEvent.click(screen.getByLabelText(/عام — يظهر في السوق/));
    await save();

    expect(put).toHaveBeenCalledWith("/courses/c-1", expect.objectContaining({ visibility: "public" }));
  });

  it("does not send the choice at all when it was not touched", async () => {
    // A course the platform HID reads as «خاص» here; echoing it back on a title
    // edit would overwrite the panel's decision.
    await openPage("hidden");
    await save();

    const body = put.mock.calls.at(-1)?.[1] as Record<string, unknown>;

    expect(body).not.toHaveProperty("visibility");
  });
});

/*
| ⛔ الظهورُ لمدرّسِ الكورسِ وحدَه (قرارُ المالك 2026-09-26): المساعدُ يُعدِّلُ
| الباقي ولا يرى الحقل، والجوابُ من الخادم (`can_change_visibility`).
*/
describe("«ظهور الكورس» for someone who may not decide it", () => {
  it.each([
    ["an assistant (false)", false],
    ["an unstamped read (absent)", "absent" as const],
  ])("is hidden for %s, and the save still carries the rest", async (_label, canChange) => {
    await openPage("public", canChange);

    expect(screen.queryByText("ظهور الكورس")).toBeNull();
    expect(screen.queryByLabelText(/خاص — لا يصل إليه/)).toBeNull();

    await save();

    const body = put.mock.calls.at(-1)?.[1] as Record<string, unknown>;

    expect(body).toHaveProperty("title", "رياضيات");
    expect(body).not.toHaveProperty("visibility");
  });
});
