import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageSessionsPage from "./page";

/*
| «حصصي» — كان يفتحُ على أوّلِ أسبوعٍ درّسه المدرّسُ في حياتِه.
|
| القائمةُ تُطلَبُ بلا وسيطٍ أصلاً وتُصفَّحُ بخمسين مرتَّبةً تصاعديّاً، فالصفحةُ الأولى
| في مساحةٍ فيها سنةُ عملٍ هي أقدمُ خمسين حصّة — تحتَ عنوانٍ يقول «حصصي»، على
| الشاشةِ التي يفتحُها المدرّسُ ليبدأَ حصّةً بعدَ دقيقة.
|
| ⚠️ ولا يراه أيُّ اختبارٍ خلفيّ: الخادمُ يُجيبُ بالضبطِ ما سُئل، والسؤالُ هو العيب.
*/

const list = vi.fn();
const create = vi.fn();

const TEACHERS = [
  { uuid: "t-1", name: "أ. سامي" },
  { uuid: "t-2", name: "أ. هدى" },
];

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    list: (params: Record<string, string>) => list(params),
    workspaceTeachers: () => Promise.resolve({ data: TEACHERS }),
    generate: () => Promise.resolve({}),
    create: (payload: Record<string, unknown>) => create(payload),
  },
}));

/*
  ⚠️ حصّةُ المجموعةِ تُسمّي مجموعتَها منذُ إنشائها. قبلَ ذلك كانت كلُّ حصّةِ مجموعةٍ
  تُنشَأُ من هذه الشاشةِ تُولَدُ بلا مجموعة — وأوّلُ مجموعةٍ يُنشِئُها المدرّسُ
  تُخرِجُها جميعاً من قوائمِ الطلاب، ولوحةُ «حصص محجوبة» ترفضُ إسنادَ ما انعقدَ منها.
*/
const cohortList = vi.fn();

vi.mock("@/lib/cohorts", () => ({
  manageCohorts: { list: (uuid: string) => cohortList(uuid) },
}));

vi.mock("@/lib/api", () => ({
  api: {
    get: () => Promise.resolve({ data: [{ uuid: "c-1", title: "رياضيات ٣ث" }] }),
  },
  fieldErrors: () => ({}),
}));

/*
  ⛔ «لا يوجد ملفّ مدرّس لحسابك» was the LAST thing a workspace owner with no
  teacher profile read, after filling both forms (2026-09-26). The page asks
  `/auth/me` up front now, so every case below names whose session it is.
*/
let mockUser: { uuid: string; teacher_profile_uuid: string | null } | null = null;
let mockAuthLoading = false;

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: mockAuthLoading }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { uuid: "u-1", teacher_profile_uuid: "p-1" };
  mockAuthLoading = false;
  list.mockResolvedValue({ data: [] });
  create.mockResolvedValue({});
  cohortList.mockResolvedValue({ data: [] });
});

/** Fill the one-off form far enough that only the group is missing. */
async function fillOneOff(seats: string) {
  render(<ManageSessionsPage />);
  await waitFor(() => expect(list).toHaveBeenCalled());

  await userEvent.selectOptions(
    await waitFor(() => document.getElementById("course_uuid") as HTMLSelectElement),
    "c-1",
  );

  await userEvent.type(document.getElementById("one_off_title") as HTMLInputElement, "تجربة");
  fireEvent.change(document.getElementById("one_off_starts_at") as HTMLInputElement, {
    target: { value: "2026-09-03T20:31" },
  });

  const seatsField = document.getElementById("one_off_seats") as HTMLInputElement;
  fireEvent.change(seatsField, { target: { value: seats } });
}

describe("ManageSessionsPage — which sessions it asks for", () => {
  it("asks for today, not for everything since the beginning", async () => {
    render(<ManageSessionsPage />);

    await waitFor(() => {
      expect(list).toHaveBeenCalled();
    });

    const params = list.mock.calls[0][0] as { from?: string; to?: string };

    // Both bounds and both the same day: `from` alone is «today onwards», which
    // is a different question and not the default.
    expect(params.from).toBeDefined();
    expect(params.to).toBe(params.from);
  });

  it("looks backwards newest first", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.click(screen.getByRole("button", { name: "السابقة" }));

    await waitFor(() => {
      const last = list.mock.calls.at(-1)?.[0] as { to?: string; order?: string };

      // ⚠️ `desc` IS THE POINT. Ascending over a past range puts page one on the
      // teacher's first ever week — the same defect wearing the other face.
      expect(last.order).toBe("desc");
      expect(last.to).toBeDefined();
    });
  });

  it("drops the upper bound when looking forwards", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.click(screen.getByRole("button", { name: "القادمة" }));

    await waitFor(() => {
      const last = list.mock.calls.at(-1)?.[0] as { from?: string; to?: string };

      expect(last.from).toBeDefined();
      expect(last.to).toBeUndefined();
    });
  });

  it("says why the list is empty in terms of the range showing", async () => {
    render(<ManageSessionsPage />);

    // «ولّد حصصاً» under «اليوم» tells a teacher with a full week that their
    // calendar is empty, which is a different and false statement.
    expect(await screen.findByText(/لا حصص لك اليوم/)).toBeTruthy();

    await userEvent.click(screen.getByRole("button", { name: "السابقة" }));

    expect(await screen.findByText(/لا حصص سابقة/)).toBeTruthy();
  });
});

/*
| الفلاتر: بالمدرّس وبالمجموعة وبالتاريخ.
|
| ⚠️ و«المجموعة» هي الكورس. لا كيانَ مجموعةٍ في هذا المنتجِ إطلاقاً، وكلُّ حصّةٍ
| قابلةٍ للجدولةِ تحملُ كورساً منذ ٠٠٦ — فالمسجَّلون فيه هم المجموعةُ الدائمة.
| و`subject` غائبٌ للسببِ المعاكس: العمودُ موجودٌ ولا سطرَ في المنتجِ يكتبُه، ففلترٌ
| عليه يُرجِعُ لا شيءَ دائماً.
*/
describe("ManageSessionsPage — the filters", () => {
  it("filters by course, and calls it a group", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.selectOptions(
      await screen.findByLabelText(/المجموعة/),
      "c-1",
    );

    await waitFor(() => {
      expect((list.mock.calls.at(-1)?.[0] as { course?: string }).course).toBe("c-1");
    });
  });

  it("filters by teacher only when the workspace has more than one", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    /*
      ⚠️ BY ID, NOT BY LABEL. The generator form above carries a «المدرّس» picker
      of its own — the two are the same word for two different questions, and a
      label query matches both. The ids are what keep them apart.

      ⚠️ AND AWAITED, BECAUSE IT HANGS OFF A DIFFERENT PROMISE. The picker is
      gated on `teachers.length > 1`, filled by `workspaceTeachers()` — while the
      wait above is on `list()`, the sessions fetch. Two independent promises, so
      which one settles first is the scheduler's business: a synchronous read here
      passed locally and on two CI runs, then failed on the third with «expected
      null not to be null» — a flake that blocks a deploy and names nothing.
    */
    const picker = await waitFor(() => {
      const found = document.getElementById("filter_teacher");

      expect(found).not.toBeNull();

      return found as HTMLSelectElement;
    });
    await userEvent.selectOptions(picker, "t-2");

    await waitFor(() => {
      expect((list.mock.calls.at(-1)?.[0] as { teacher?: string }).teacher).toBe("t-2");
    });
  });

  it("keeps the range shortcut lit while a course narrows it", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.selectOptions(await screen.findByLabelText(/المجموعة/), "c-1");

    // Narrowing to one course does not leave «اليوم» — the dates are unchanged,
    // and un-lighting the button would say otherwise.
    const params = list.mock.calls.at(-1)?.[0] as { from?: string; to?: string };

    expect(params.to).toBe(params.from);
  });

  it("sends nothing for a filter the teacher never chose", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    // An empty string is not a filter: sent, the server would look for a course
    // whose uuid is «» and answer with an empty calendar.
    expect(Object.values(list.mock.calls[0][0] as Record<string, string>)).not.toContain("");
  });

  it("clears every narrowing at once", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.selectOptions(await screen.findByLabelText(/المجموعة/), "c-1");
    await waitFor(() => expect(list.mock.calls.length).toBeGreaterThan(1));

    await userEvent.click(screen.getByRole("button", { name: "امسح الفلاتر" }));

    await waitFor(() => {
      // Everything gone, dates included — a teacher who filtered themselves down
      // to nothing should not undo four fields to find out that is what happened.
      expect(list.mock.calls.at(-1)?.[0]).toEqual({ order: "asc" });
    });
  });

  it("says which filter emptied the list, not «generate some sessions»", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    await userEvent.selectOptions(await screen.findByLabelText(/المجموعة/), "c-1");

    expect(await screen.findByText(/لا حصص تطابق هذه الفلاتر/)).toBeTruthy();
  });
});

/*
| ⚠️ حقلُ `datetime-local` لا يحملُ منطقةً زمنيّة، والخادمُ يعملُ بـUTC.
|
| فالنصُّ العاري `2026-09-03T20:31` يُقرَأُ هناك ٢٠:٣١ UTC — أي ٢٣:٣١ عندَ مدرّسٍ
| في قطر. بلاغٌ من الإنتاجِ في 2026-09-03: «تعذّر الدخول» على حصّةٍ جدولَها
| المدرّسُ لتلك الدقيقةِ نفسِها، و`joinWindowCovers()` تُجيبُ **بحقٍّ** بالنفي عن
| غرفةٍ تبعدُ ١٧٩ دقيقة.
|
| ⚠️ و`after:now` عاجزةٌ عن رؤيتِه — ٢٠:٣١ UTC مستقبلٌ صحيح. ولا اختبارٌ خلفيٌّ
| يراه: الخادمُ يخزّنُ بالضبطِ ما وصلَه، والقيمةُ الواصلةُ هي العيب. والمتصفّحُ هو
| الطرفُ الوحيدُ الذي يعرفُ المنطقةَ التي قصدَها المشغّل، فالحارسُ هنا أو لا مكانَ له.
*/
describe("ManageSessionsPage — the one-off form sends an instant, not a wall clock", () => {
  it("converts «موعد البدء» to an absolute instant before sending it", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    const WALL_CLOCK = "2026-09-03T20:31";

    // ⚠️ NO TEACHER PICKER ANY MORE — the server derives the profile from the
    // signed-in teacher. The course is the one field still shared with the card
    // above, and it is what gates this button.
    await userEvent.selectOptions(
      await waitFor(() => document.getElementById("course_uuid") as HTMLSelectElement),
      "c-1",
    );

    await userEvent.type(document.getElementById("one_off_title") as HTMLInputElement, "تجربة");

    const at = document.getElementById("one_off_starts_at") as HTMLInputElement;
    fireEvent.change(at, { target: { value: WALL_CLOCK } });

    await userEvent.click(screen.getByRole("button", { name: "إنشاء الحصة" }));

    await waitFor(() => expect(create).toHaveBeenCalled());

    const payload = create.mock.calls.at(-1)?.[0] as {
      starts_at: string;
      teacher_profile_uuid?: string;
    };

    // The field is not merely blank — it is absent. A sent empty string would be
    // a uuid the server must refuse, instead of a question it never had to ask.
    expect(payload.teacher_profile_uuid).toBeUndefined();

    // The naive string is the defect itself; anything but it is not the assertion.
    expect(payload.starts_at).not.toBe(WALL_CLOCK);
    // And it is the SAME moment the operator meant, read in this browser's zone —
    // which is what makes the check hold in CI (UTC) and on a Qatari laptop alike.
    expect(payload.starts_at).toBe(new Date(WALL_CLOCK).toISOString());
  });
});

describe("a group lesson names its group", () => {
  it("asks for no group at all while the lesson is a private hour", async () => {
    await fillOneOff("1");

    expect(document.getElementById("one_off_cohort")).toBeNull();
    expect(screen.getByRole("button", { name: "إنشاء الحصة" }).hasAttribute("disabled")).toBe(false);
  });

  it("sends the chosen group, and will not submit without one", async () => {
    cohortList.mockResolvedValue({
      data: [{ uuid: "g-1", name: "مجموعة السبت", status: "open" }],
    });

    await fillOneOff("6");

    const picker = await waitFor(() => {
      const found = document.getElementById("one_off_cohort");

      expect(found).not.toBeNull();

      return found as HTMLSelectElement;
    });

    // Nothing chosen yet: a press is answered HERE, beside the field — not by a
    // 422 after the round trip, and not by a grey button that says nothing.
    await userEvent.click(screen.getByRole("button", { name: "إنشاء الحصة" }));

    expect(create).not.toHaveBeenCalled();
    expect(screen.getAllByText(/اختر المجموعة التي تنتمي إليها الحصة/).length).toBeGreaterThan(0);

    await userEvent.selectOptions(picker, "g-1");
    await userEvent.click(screen.getByRole("button", { name: "إنشاء الحصة" }));

    await waitFor(() => expect(create).toHaveBeenCalled());

    const payload = create.mock.calls.at(-1)?.[0] as { cohort_uuid?: string; type: string };

    expect(payload.type).toBe("group");
    expect(payload.cohort_uuid).toBe("g-1");
  });

  it("points at the way to make one when the course has no group", async () => {
    await fillOneOff("6");

    expect(await screen.findByText("أنشئ مجموعة لهذا الكورس")).toBeTruthy();

    // No picker to choose from: a press sends nothing the server will refuse,
    // and SAYS why rather than doing nothing.
    await userEvent.click(screen.getByRole("button", { name: "إنشاء الحصة" }));

    expect(create).not.toHaveBeenCalled();
    expect(screen.getByText(/لا مجموعة في هذا الكورس بعد\./)).toBeTruthy();
  });
});

describe("a press before the form is complete says what is missing", () => {
  it("names the course, the title and the start instead of a dead button", async () => {
    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    const button = screen.getByRole("button", { name: "إنشاء الحصة" });

    // ⛔ It was disabled until every field was full, with nothing saying why.
    expect(button.hasAttribute("disabled")).toBe(false);

    await userEvent.click(button);

    expect(create).not.toHaveBeenCalled();
    expect(screen.getByText("تعذّر الإنشاء")).toBeTruthy();
    expect(screen.getAllByText(/اختر الكورس من الحقل أعلى الصفحة/).length).toBeGreaterThan(0);
    expect(screen.getAllByText("اكتب عنوان الحصة.").length).toBeGreaterThan(0);
    expect(screen.getAllByText("اختر موعد البدء.").length).toBeGreaterThan(0);
  });
});

describe("an account with no teacher profile", () => {
  it("is told up front, and is never shown the two forms", async () => {
    mockUser = { uuid: "o-1", teacher_profile_uuid: null };

    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(screen.getByText("لا يمكن إنشاء حصص من هذا الحساب بعد")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "إنشاء الحصة" })).toBeNull();
    expect(screen.queryByRole("button", { name: "توليد" })).toBeNull();
    // The workspace's sessions are still listed below.
    expect(await screen.findByText("لا حصص هنا")).toBeTruthy();
  });

  it("decides nothing while the session is still being restored", async () => {
    mockUser = null;
    mockAuthLoading = true;

    render(<ManageSessionsPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(screen.queryByText("لا يمكن إنشاء حصص من هذا الحساب بعد")).toBeNull();
  });
});
