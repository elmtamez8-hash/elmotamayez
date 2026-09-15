import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { fireEvent } from "@testing-library/dom";
import { LessonAudienceFields } from "./LessonAudienceFields";

/*
  ٠٢٦ · FR-001 · FR-003 · FR-006 — المفتاحانِ الصريحان.

  ⚠️ **لا يراهما اختبارُ خادمٍ ولا `tsc`.** «لا يُعرَضُ محورٌ لا جوابَ له»
  قرارٌ في المتصفّحِ وحدَه، و«فارغةٌ = للجميع» و«`null` = يظهر الآن» هما
  الحمولةُ التي تُرسَل — وحقلٌ يُرسِلُ لا شيءَ عندَ «للجميع» أو عندَ «يظهر
  الآن» يجعلُ إلغاءَ التضييقِ وفكَّ الربطِ زرَّينِ بلا أثر، بينما الخادمُ
  يفرّقُ بينَ الغيابِ والقيمةِ الصريحة.
*/

const listCohorts = vi.fn();
const listSessions = vi.fn();

vi.mock("@/lib/cohorts", () => ({
  manageCohorts: { list: (...args: unknown[]) => listCohorts(...args) },
}));

vi.mock("@/lib/class-sessions", () => ({
  classSessions: { list: (...args: unknown[]) => listSessions(...args) },
}));

function cohort(uuid: string, name: string, status = "open") {
  return { uuid, name, status, description: null, capacity: null, seats_left: null, is_full: false };
}

function session(uuid: string, title: string, status = "scheduled") {
  return {
    uuid,
    title,
    status,
    starts_at: "2026-10-03T14:00:00Z",
    timezone: "Asia/Qatar",
  };
}

function fields(props: Partial<Parameters<typeof LessonAudienceFields>[0]> = {}) {
  return (
    <LessonAudienceFields
      courseUuid="c-1"
      cohortUuids={[]}
      releaseSessionUuid={null}
      onChangeCohorts={vi.fn()}
      onChangeRelease={vi.fn()}
      {...props}
    />
  );
}

beforeEach(() => {
  listCohorts.mockReset();
  listSessions.mockReset();
  listCohorts.mockResolvedValue({ data: [] });
  listSessions.mockResolvedValue({ data: [] });
});

describe("LessonAudienceFields", () => {
  it("draws nothing at all on a course with neither groups nor sessions", async () => {
    const { container } = render(fields());

    await waitFor(() => expect(listCohorts).toHaveBeenCalledWith("c-1"));
    await waitFor(() => expect(listSessions).toHaveBeenCalled());

    expect(container.innerHTML).toBe("");
  });

  it("offers the course's groups once it has them", async () => {
    listCohorts.mockResolvedValue({ data: [cohort("g-1", "مجموعة السبت")] });

    render(fields());

    expect(await screen.findByLabelText("لمن هذا العنصر")).toBeDefined();
  });

  /*
    ⚠️ **ومحورٌ بلا الآخَر.** كورسٌ بحصصٍ ولا مجموعاتٍ يسألُ «متى يظهر» ولا
    يسألُ «لمن» — وحقلٌ فارغٌ من محورٍ لا جوابَ له ضجيجٌ بجوارِ كلِّ عنصر.
  */
  it("offers the release picker on a course that has sessions and no groups", async () => {
    listSessions.mockResolvedValue({ data: [session("s-1", "حصة الأحد")] });

    render(fields());

    expect(await screen.findByLabelText("متى يظهر هذا العنصر")).toBeDefined();
    expect(screen.queryByLabelText("لمن هذا العنصر")).toBeNull();
  });

  /*
    ⚠️ **المؤرشَفةُ لا تُعرَض.** تضييقٌ على مجموعةٍ انتهت هو إخفاءٌ عن الجميع
    بلا أن يقولَ أحدٌ ذلك — ولا طالبَ عضوٌ فيها اليوم.
  */
  it("does not offer an archived group as somewhere to narrow to", async () => {
    listCohorts.mockResolvedValue({
      data: [cohort("g-1", "مجموعة السبت"), cohort("g-2", "مجموعة منتهية", "archived")],
    });

    render(fields());

    fireEvent.click(await screen.findByLabelText("لمن هذا العنصر"));

    expect(screen.queryByRole("checkbox", { name: "مجموعة منتهية" })).toBeNull();
    expect(screen.getByRole("checkbox", { name: "مجموعة السبت" })).toBeDefined();
  });

  it("sends an empty list when the last group is unticked, not nothing", async () => {
    listCohorts.mockResolvedValue({ data: [cohort("g-1", "مجموعة السبت")] });

    const onChangeCohorts = vi.fn();

    render(fields({ cohortUuids: ["g-1"], onChangeCohorts }));

    fireEvent.click(await screen.findByLabelText("لمن هذا العنصر"));
    fireEvent.click(screen.getByRole("checkbox", { name: "مجموعة السبت" }));

    expect(onChangeCohorts).toHaveBeenCalledWith([]);
  });

  /*
    ⛔ **و«يظهر الآن» تُرسِلُ `null` صراحةً** — وهي مخرجُ FR-008 لحصّةٍ لم
    تُسلَّمْ ولم تُلغَ. أرسِلْ `undefined` أو لا شيءَ يصرْ فكُّ الربطِ زرّاً
    بلا أثرٍ إطلاقاً، والعنصرُ مقفولاً إلى الأبد.
  */
  it("sends an explicit null when the teacher chooses «appears now»", async () => {
    listSessions.mockResolvedValue({ data: [session("s-1", "حصة الأحد")] });

    const onChangeRelease = vi.fn();

    render(fields({ releaseSessionUuid: "s-1", onChangeRelease }));

    fireEvent.change(await screen.findByLabelText("متى يظهر هذا العنصر"), {
      target: { value: "" },
    });

    expect(onChangeRelease).toHaveBeenCalledWith(null);
  });

  /*
    ⚠️ **الملغاةُ لا تُعرَض.** حصّةٌ لن تُعقَدَ أبداً مُفرَجٌ عنها بالفعل، فربطُ
    عنصرٍ بها «يظهر الآن» يلبسُ اسمَ حصّة.
  */
  it("does not offer a cancelled session as something to wait for", async () => {
    listSessions.mockResolvedValue({
      data: [session("s-1", "حصة الأحد"), session("s-2", "حصة ملغاة", "cancelled")],
    });

    render(fields());

    const select = await screen.findByLabelText("متى يظهر هذا العنصر");

    expect(select.textContent).toContain("حصة الأحد");
    expect(select.textContent).not.toContain("حصة ملغاة");
  });

  /*
    ⛔ **والربطُ القائمُ يُعرَضُ ولو لم يكنْ في الخمسين التي ردَّها الخادم.**
    بدونَه يرسمُ الحقلُ «يظهر الآن» لعنصرٍ مقفولٍ فعلاً — الأداةُ الوحيدةُ التي
    تُظهِرُ الحالَ تكذبُ فيه، ولا يبقى للمدرّسِ طريقٌ لفكِّ الربط.
  */
  it("still shows a link to a session that fell outside the fetched page", async () => {
    listSessions.mockResolvedValue({ data: [session("s-1", "حصة الأحد")] });

    render(fields({ releaseSessionUuid: "s-old" }));

    const select = (await screen.findByLabelText("متى يظهر هذا العنصر")) as HTMLSelectElement;

    expect(select.value).toBe("s-old");
  });

  /*
    ⚠️ **وفشلُ القراءةِ يُخفي الحقلَ ولا يُعطِّلُ المحرّر.** لافتةُ خطأٍ هنا
    تُوقِفُ حفظَ عنوانٍ لا علاقةَ له بالمجموعاتِ ولا بالحصص.
  */
  it("stays out of the way when neither list can be read", async () => {
    listCohorts.mockRejectedValue(new Error("خطأ"));
    listSessions.mockRejectedValue(new Error("خطأ"));

    const { container } = render(fields());

    await waitFor(() => expect(listCohorts).toHaveBeenCalled());
    await waitFor(() => expect(listSessions).toHaveBeenCalled());

    expect(container.innerHTML).toBe("");
  });
});
