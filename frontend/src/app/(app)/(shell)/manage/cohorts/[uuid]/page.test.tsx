import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageCohortPage from "./page";

/*
| صفحةُ المجموعة — والميزةُ التي لم تكنْ موجودةً: إضافةُ مواعيدَ لها.
|
| ⚠️ شاشةُ المجموعاتِ كانت تُنشِئُ مجموعةً وتُسنِدُ إليها حصصاً موجودةً، ولا شيءَ
| غيرَ ذلك: لا سبيلَ لإضافةِ موعدٍ إلى مجموعة، ولا لرؤيةِ مواعيدِها أبعدَ من ثلاثِ
| عباراتٍ مختصرة.
|
| ⚠️ و«من جدولي الأسبوعيّ» يُولِّدُ حصصاً ولا يُشيرُ إلى شقوقِ التوفّر: `SetAvailability`
| يحذفُ الصفوفَ ويُعيدُ إنشاءَها عندَ كلِّ حفظ، فمعرّفُ الشقِّ يتغيّرُ كلّما أعادَ
| المدرّسُ كتابةَ أسبوعِه — ومجموعةٌ تُشيرُ إلى المعرّفاتِ تفقدُ جدولَها عندَ أوّلِ
| تعديل. المحفوظُ حصّةٌ لها تاريخُها وساعتُها.
*/

const show = vi.fn();
const members = vi.fn();
const history = vi.fn();
const update = vi.fn();
const archive = vi.fn();

const list = vi.fn();
const generate = vi.fn();
const create = vi.fn();

vi.mock("@/lib/cohorts", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/cohorts")>()),
  manageCohorts: {
    show: (uuid: string) => show(uuid),
    members: (uuid: string) => members(uuid),
    history: (uuid: string) => history(uuid),
    update: (...args: unknown[]) => update(...args),
    archive: (uuid: string) => archive(uuid),
  },
}));

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    list: (params: Record<string, string>) => list(params),
    generate: (body: Record<string, unknown>) => generate(body),
    create: (body: Record<string, unknown>) => create(body),
  },
}));

const GROUP = {
  uuid: "g-1",
  name: "مجموعة السبت",
  description: null,
  status: "open" as const,
  capacity: 12,
  members_count: 3,
  seats_left: 9,
  is_full: false,
  is_joinable: true,
  schedule_preview: ["السبت 16:00"],
  course: { uuid: "c-1", title: "الفيزياء" },
};

beforeEach(() => {
  vi.clearAllMocks();
  show.mockResolvedValue(GROUP);
  members.mockResolvedValue({ data: [] });
  history.mockResolvedValue({ data: [] });
  update.mockResolvedValue({});
  archive.mockResolvedValue({});
  list.mockResolvedValue({ data: [] });
  generate.mockResolvedValue({ created: [{}, {}], skipped: [] });
  create.mockResolvedValue({});
});

async function open() {
  // `use(params)` suspends, and a root with no fallback renders NOTHING until
  // the promise settles — `act` is what flushes it.
  await act(async () => {
    render(<ManageCohortPage params={Promise.resolve({ uuid: "g-1" })} />);
  });
}

describe("a group's own page", () => {
  it("asks only for this group's sessions, from today onwards", async () => {
    await open();

    const params = list.mock.calls[0][0] as { cohort?: string; from?: string; order?: string };

    // A group in its second term has hundreds of past lessons; the question
    // this page asks is «when do we meet next».
    expect(params.cohort).toBe("g-1");
    expect(params.from).toBeDefined();
    expect(params.order).toBe("asc");
  });

  it("generates the group's dates from the weekly schedule, into the group", async () => {
    await open();

    fireEvent.change(screen.getByLabelText("من تاريخ"), { target: { value: "2026-10-01" } });
    fireEvent.change(screen.getByLabelText("إلى تاريخ"), { target: { value: "2026-10-31" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "ولِّد المواعيد" }));
    });

    const body = generate.mock.calls.at(-1)?.[0] as Record<string, unknown>;

    expect(body.cohort_uuid).toBe("g-1");
    expect(body.course_uuid).toBe("c-1");
    // A group lesson, and the seats are the group's own ceiling — the server
    // refuses a group session that names no group.
    expect(body.type).toBe("group");
    expect(body.seats_total).toBe(12);

    // What was created and what was skipped, in words: a generator that
    // silently drops clashes leaves a teacher believing their month is full.
    expect(await screen.findByText(/أُنشِئت 2 حصة/)).toBeTruthy();
  });

  it("adds a single date outside the weekly pattern, into the same group", async () => {
    await open();

    fireEvent.change(screen.getByLabelText("عنوان الحصة"), { target: { value: "تعويضية" } });
    fireEvent.change(screen.getByLabelText("موعد البدء"), {
      target: { value: "2026-10-06T18:00" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أضِف الموعد" }));
    });

    const body = create.mock.calls.at(-1)?.[0] as Record<string, unknown>;

    expect(body.cohort_uuid).toBe("g-1");
    expect(body.type).toBe("group");
    // The naive wall clock is the defect itself — the API runs on UTC.
    expect(body.starts_at).not.toBe("2026-10-06T18:00");
  });

  it("opens the lesson from its own row — the title is the link", async () => {
    list.mockResolvedValue({
      data: [
        {
          uuid: "sess-7",
          title: "المتجهات",
          status: "scheduled",
          starts_at: "2026-10-10T13:00:00Z",
          timezone: "Asia/Qatar",
        },
      ],
    });

    await open();

    /*
    | ⚠️ الصفُّ كان يحملُ كلمةَ «تعديل» وحدَها في آخرِه. للحصّةِ شاشةٌ كاملةٌ —
    | الحضورُ والغرفةُ والتسجيلُ والإلغاء — و«تعديل» تَعِدُ بحقلٍ واحد، فلا شيءَ
    | في الصفِّ كان يقولُ إنّ للحصّةِ مكاناً يُدخَلُ إليه.
    */
    expect(screen.getByText("المتجهات").closest("a")?.getAttribute("href")).toBe(
      "/manage/sessions/sess-7",
    );
    expect(screen.getByRole("link", { name: "افتح الحصة" }).getAttribute("href")).toBe(
      "/manage/sessions/sess-7",
    );
  });

  it("clears the description rather than leaving the old text standing", async () => {
    show.mockResolvedValue({ ...GROUP, description: "كل سبت" });

    await open();

    fireEvent.click(screen.getByRole("button", { name: "تعديل" }));
    fireEvent.change(screen.getByLabelText("الوصف"), { target: { value: "  " } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ" }));
    });

    const body = update.mock.calls.at(-1)?.[1] as Record<string, unknown>;

    // `null`, not an omitted key: an omission would leave «كل سبت» in place with
    // no way to remove it.
    expect(body.description).toBeNull();
  });
});
