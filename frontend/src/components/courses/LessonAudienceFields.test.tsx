import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { fireEvent } from "@testing-library/dom";
import { LessonAudienceFields } from "./LessonAudienceFields";

/*
  ٠٢٦ · FR-001 · FR-003 — المفتاحُ الصريح.

  ⚠️ **لا يراه اختبارُ خادمٍ ولا `tsc`.** «لا يُعرَضُ على كورسٍ بلا مجموعات»
  قرارٌ في المتصفّحِ وحدَه، و«فارغةٌ = للجميع» هي الحمولةُ التي تُرسَل — وحقلٌ
  يُرسِلُ لا شيءَ عندَ «للجميع» يجعلُ إلغاءَ التضييقِ زرّاً بلا أثر، بينما
  الخادمُ يفرّقُ بينَ الغيابِ والفارغة.
*/

const list = vi.fn();

vi.mock("@/lib/cohorts", () => ({
  manageCohorts: { list: (...args: unknown[]) => list(...args) },
}));

function cohort(uuid: string, name: string, status = "open") {
  return { uuid, name, status, description: null, capacity: null, seats_left: null, is_full: false };
}

beforeEach(() => {
  list.mockReset();
});

describe("LessonAudienceFields", () => {
  it("draws nothing at all on a course that has no groups", async () => {
    list.mockResolvedValue({ data: [] });

    const { container } = render(
      <LessonAudienceFields courseUuid="c-1" value={[]} onChange={vi.fn()} />,
    );

    await waitFor(() => expect(list).toHaveBeenCalledWith("c-1"));

    expect(container.innerHTML).toBe("");
  });

  it("offers the course's groups once it has them", async () => {
    list.mockResolvedValue({ data: [cohort("g-1", "مجموعة السبت")] });

    render(<LessonAudienceFields courseUuid="c-1" value={[]} onChange={vi.fn()} />);

    expect(await screen.findByLabelText("لمن هذا العنصر")).toBeDefined();
  });

  /*
    ⚠️ **المؤرشَفةُ لا تُعرَض.** تضييقٌ على مجموعةٍ انتهت هو إخفاءٌ عن الجميع
    بلا أن يقولَ أحدٌ ذلك — ولا طالبَ عضوٌ فيها اليوم.
  */
  it("does not offer an archived group as somewhere to narrow to", async () => {
    list.mockResolvedValue({
      data: [cohort("g-1", "مجموعة السبت"), cohort("g-2", "مجموعة منتهية", "archived")],
    });

    render(<LessonAudienceFields courseUuid="c-1" value={[]} onChange={vi.fn()} />);

    fireEvent.click(await screen.findByLabelText("لمن هذا العنصر"));

    expect(screen.queryByRole("checkbox", { name: "مجموعة منتهية" })).toBeNull();
    expect(screen.getByRole("checkbox", { name: "مجموعة السبت" })).toBeDefined();
  });

  it("sends an empty list when the last group is unticked, not nothing", async () => {
    list.mockResolvedValue({ data: [cohort("g-1", "مجموعة السبت")] });

    const onChange = vi.fn();

    render(<LessonAudienceFields courseUuid="c-1" value={["g-1"]} onChange={onChange} />);

    fireEvent.click(await screen.findByLabelText("لمن هذا العنصر"));
    fireEvent.click(screen.getByRole("checkbox", { name: "مجموعة السبت" }));

    expect(onChange).toHaveBeenCalledWith([]);
  });

  /*
    ⚠️ **وفشلُ القراءةِ يُخفي الحقلَ ولا يُعطِّلُ المحرّر.** لافتةُ خطأٍ هنا
    تُوقِفُ حفظَ عنوانٍ لا علاقةَ له بالمجموعات.
  */
  it("stays out of the way when the group list cannot be read", async () => {
    list.mockRejectedValue(new Error("خطأ"));

    const { container } = render(
      <LessonAudienceFields courseUuid="c-1" value={[]} onChange={vi.fn()} />,
    );

    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(container.innerHTML).toBe("");
  });
});
