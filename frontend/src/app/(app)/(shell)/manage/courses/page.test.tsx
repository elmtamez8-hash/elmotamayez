import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageCoursesPage from "./page";

/*
| «إدارة الكورسات» — الفلاتر تُبنى من المجموعةِ المعروضةِ نفسِها.
|
| ⚠️ فلترُ المادّةِ يظهرُ **إذا كان المدرّسُ يدرّسُ أكثرَ من مادّة**، ومصدرُ هذه
| الحقيقةِ هو كورساتُه لا ملفُّه: مدرّسٌ أعلنَ ثلاثَ موادَّ في طلبِه وكلُّ كورساتِه
| في واحدةٍ لا شيءَ عندَه ليُفلترَه، ومدرّسٌ اعتُمِدَ في مادّةٍ ثمّ ألّفَ في غيرِها
| كان سيُعرَضُ عليه فلترٌ يُخفي نصفَ عملِه.
|
| ⚠️ والمرحلةُ قد تكونُ `null` — بل هي كذلك في ٩٥ من ٩٦ صفّاً — فـ«بلا مرحلة»
| حالةٌ تُفلترُ بها، لا قيمةٌ غائبةٌ تُحذَف.
*/

const get = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path), delete: vi.fn() },
}));

const TEACHER = ["courses.create", "courses.update", "courses.delete", "courses.publish"];
// `RolePermissionMatrix`'s assistant: writes a course, never deletes or publishes one.
const ASSISTANT = ["courses.create", "courses.update"];

let mockUser: { permissions: string[]; current_workspace: { uuid: string; name: string } | null } = {
  permissions: TEACHER,
  current_workspace: { uuid: "w-1", name: "أكاديمية سامي" },
};

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

/** `/assistants/me` — none by default: a teacher assists nobody. */
let mine: unknown[] = [];

const course = (over: Record<string, unknown>) => ({
  uuid: "c-1",
  title: "كورس",
  slug: "k",
  description: "وصف",
  price_minor: 0,
  currency: "QAR",
  status: "draft",
  visibility: "private",
  is_sequential: true,
  is_free: true,
  language: "ar",
  duration_seconds: 0,
  created_at: "2026-01-01",
  grade_level: null,
  subject: null,
  cohorts: [],
  ...over,
});

const STAGES = {
  data: [
    { slug: "primary", name: "المرحلة الابتدائية" },
    { slug: "secondary", name: "المرحلة الثانوية" },
  ],
};

function answer(courses: ReturnType<typeof course>[]) {
  get.mockImplementation((path: string) => {
    if (path.startsWith("/courses")) {
      return Promise.resolve({ data: courses, meta: { total: courses.length } });
    }
    if (path === "/signup/grade-levels") return Promise.resolve(STAGES);
    if (path === "/assistants/me") return Promise.resolve({ data: mine });

    return Promise.reject(new Error(`unexpected ${path}`));
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mine = [];
  mockUser = { permissions: TEACHER, current_workspace: { uuid: "w-1", name: "أكاديمية سامي" } };
});

describe("manage courses", () => {
  it("puts each group and the times it meets on the course card", async () => {
    answer([
      course({
        cohorts: [
          {
            uuid: "g-1",
            name: "المجموعة الأولى",
            description: null,
            status: "open",
            capacity: 10,
            members_count: 2,
            seats_left: 8,
            is_full: false,
            is_joinable: true,
            schedule_preview: ["السبت 16:00", "الثلاثاء 18:00"],
          },
        ],
      }),
    ]);

    render(<ManageCoursesPage />);

    expect(await screen.findByText("المجموعة الأولى")).toBeTruthy();
    expect(screen.getByText("السبت 16:00 · الثلاثاء 18:00")).toBeTruthy();
  });

  it("says so rather than showing nothing when a course has no group yet", async () => {
    answer([course({})]);

    render(<ManageCoursesPage />);

    expect(await screen.findByText("لا مجموعات بعد.")).toBeTruthy();
  });

  it("hides the subject filter for a teacher of one subject and shows it for two", async () => {
    const maths = { uuid: "s-1", label: "الرياضيات" };

    answer([
      course({ uuid: "a", subject: maths }),
      course({ uuid: "b", title: "كورس ب", subject: maths }),
    ]);

    const one = render(<ManageCoursesPage />);
    await screen.findByText("كورس ب");
    expect(screen.queryByLabelText("المادّة")).toBeNull();
    one.unmount();

    answer([
      course({ uuid: "a", subject: maths }),
      course({ uuid: "b", title: "كورس ب", subject: { uuid: "s-2", label: "الفيزياء" } }),
    ]);

    render(<ManageCoursesPage />);
    await screen.findByText("كورس ب");
    expect(screen.getByLabelText("المادّة")).toBeTruthy();
  });

  it("narrows by stage, and «no stage» is one of the answers", async () => {
    answer([
      course({ uuid: "a", title: "ثانوي", grade_level: "secondary" }),
      course({ uuid: "b", title: "بلا مرحلة له", grade_level: null }),
    ]);

    render(<ManageCoursesPage />);
    await screen.findByText("ثانوي");

    const filter = screen.getByLabelText("المرحلة الدراسية") as HTMLSelectElement;
    fireEvent.change(filter, { target: { value: "secondary" } });

    await waitFor(() => expect(screen.queryByText("بلا مرحلة له")).toBeNull());
    expect(screen.getByText("ثانوي")).toBeTruthy();

    fireEvent.change(filter, { target: { value: "__none" } });

    await waitFor(() => expect(screen.queryByText("ثانوي")).toBeNull());
    expect(screen.getByText("بلا مرحلة له")).toBeTruthy();
  });
});

/*
| ما يعرضُه الخادمُ ثمّ يرفضُه: المساعدُ يحملُ `courses.create` ولا يحملُ
| `courses.delete` (`RolePermissionMatrix`) — فكانت سلّةُ الحذفِ على كلِّ بطاقةٍ
| زرّاً يُجيبُ ٤٠٣.
*/
describe("what an assistant is offered", () => {
  it("shows the teacher «كورس جديد» and a delete on every card", async () => {
    answer([course({})]);

    render(<ManageCoursesPage />);
    await screen.findByText("كورس");

    expect(screen.getByRole("link", { name: "كورس جديد" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "احذف كورس كورس" })).toBeTruthy();
  });

  it("draws no delete for a reader without courses.delete", async () => {
    mockUser = { ...mockUser, permissions: ASSISTANT };
    answer([course({})]);

    render(<ManageCoursesPage />);
    await screen.findByText("كورس");

    expect(screen.queryByRole("button", { name: "احذف كورس كورس" })).toBeNull();
    // Creating is theirs, so the button stays.
    expect(screen.getByRole("link", { name: "كورس جديد" })).toBeTruthy();
  });

  it("offers no «كورس جديد» without courses.create", async () => {
    mockUser = { ...mockUser, permissions: ["courses.update"] };
    answer([]);

    render(<ManageCoursesPage />);
    await screen.findByText("لا كورسات بعد");

    expect(screen.queryByRole("link", { name: "كورس جديد" })).toBeNull();
    expect(screen.queryByRole("link", { name: "أنشئ كورساً" })).toBeNull();
  });

  it("names the courses a confined assistant works on, in this team only", async () => {
    mockUser = { ...mockUser, permissions: ASSISTANT };
    mine = [
      {
        uuid: "a-1",
        assistant: { uuid: "u-1", name: "مساعد" },
        workspace: { uuid: "w-1", name: "أكاديمية سامي" },
        is_confined: true,
        courses: [{ uuid: "c-1", title: "الجبر" }, { uuid: "c-2", title: "الهندسة" }],
        revoked_at: null,
      },
      {
        uuid: "a-2",
        assistant: { uuid: "u-1", name: "مساعد" },
        workspace: { uuid: "w-2", name: "فريق آخر" },
        is_confined: true,
        courses: [{ uuid: "c-9", title: "كيمياء فريق آخر" }],
        revoked_at: null,
      },
    ];
    answer([course({})]);

    render(<ManageCoursesPage />);

    expect(await screen.findByText(/تعمل على: الجبر · الهندسة/)).toBeTruthy();
    expect(screen.queryByText(/كيمياء فريق آخر/)).toBeNull();
  });

  it("says nothing to an assistant who works on every course", async () => {
    mockUser = { ...mockUser, permissions: ASSISTANT };
    mine = [
      {
        uuid: "a-1",
        assistant: { uuid: "u-1", name: "مساعد" },
        workspace: { uuid: "w-1", name: "أكاديمية سامي" },
        is_confined: false,
        courses: [],
        revoked_at: null,
      },
    ];
    answer([course({})]);

    render(<ManageCoursesPage />);
    await screen.findByText("كورس");
    await waitFor(() => expect(get).toHaveBeenCalledWith("/assistants/me"));

    expect(screen.queryByText(/تعمل على/)).toBeNull();
  });
});
