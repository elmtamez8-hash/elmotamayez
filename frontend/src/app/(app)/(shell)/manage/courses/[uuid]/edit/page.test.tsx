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

let mockUser: { permissions: string[] } = { permissions: ["courses.update", "courses.publish"] };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: EditCoursePage } = await import("./page");

function course(visibility: string, canChange: boolean | "absent", canPrice: boolean | "absent" = true) {
  return {
    // «absent» is a read nobody stamped — the key is missing, not false.
    ...(canChange === "absent" ? {} : { can_change_visibility: canChange }),
    ...(canPrice === "absent" ? {} : { can_change_pricing: canPrice }),
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

async function openPage(
  visibility: string,
  canChange: boolean | "absent" = true,
  canPrice: boolean | "absent" = true,
) {
  get.mockImplementation((path: string) =>
    Promise.resolve(path === "/courses/c-1" ? course(visibility, canChange, canPrice) : { data: [] }),
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
  mockUser = { permissions: ["courses.update", "courses.publish"] };
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

/*
| «انشر الكورس» / «إلغاء النشر» يسألانِ `courses.publish`، والمساعدُ لا يحملُها —
| فالزرُّ يغيبُ وتبقى جملةٌ تقولُ مَن يتولّاه، بدلَ ضغطةٍ تُجيبُ ٤٠٣.
*/
describe("publishing, for someone who may not publish", () => {
  it("offers «إلغاء النشر» to the teacher", async () => {
    await openPage("public");

    expect(screen.getByRole("button", { name: "إلغاء النشر" })).toBeTruthy();
  });

  it("draws neither button for a reader without courses.publish, and says who does it", async () => {
    mockUser = { permissions: ["courses.update"] };
    await openPage("public");

    expect(screen.queryByRole("button", { name: "إلغاء النشر" })).toBeNull();
    expect(screen.queryByRole("button", { name: "انشر الكورس" })).toBeNull();
    expect(screen.getByText("نشر الكورس وإلغاء نشره يتولّاهما المدرّس.")).toBeTruthy();
  });
});

/*
| ⛔ «كورس مجاني» والعملةُ لمدرّسِ الكورسِ وحدَه كذلك (قرارُ المالك 2026-09-30):
| الجوابُ `can_change_pricing` من الخادم، والمساعدُ يقرأُ الحالَ وسببَ غيابِ
| الخيار، وحفظُه لا يحملُ مفتاحَي السعر.
*/
describe("«كورس مجاني» on the edit form", () => {
  it("offers it to the course's teacher and sends the price keys", async () => {
    await openPage("public", true, true);

    fireEvent.click(screen.getByLabelText(/كورس مجاني/));
    await save();

    const body = put.mock.calls.at(-1)?.[1] as Record<string, unknown>;

    expect(body).toHaveProperty("is_free_enrollment", true);
    expect(body).toHaveProperty("currency", "QAR");
  });

  it.each([
    ["an assistant (false)", false],
    ["an unstamped read (absent)", "absent" as const],
  ])("is hidden for %s, says why, and the save carries no price key", async (_label, canPrice) => {
    await openPage("public", true, canPrice);

    expect(screen.queryByLabelText(/كورس مجاني/)).toBeNull();
    expect(screen.getByText(/يقرّرهما مدرّس الكورس/)).toBeTruthy();

    await save();

    const body = put.mock.calls.at(-1)?.[1] as Record<string, unknown>;

    expect(body).toHaveProperty("title", "رياضيات");
    expect(body).not.toHaveProperty("is_free_enrollment");
    expect(body).not.toHaveProperty("currency");
  });
});
