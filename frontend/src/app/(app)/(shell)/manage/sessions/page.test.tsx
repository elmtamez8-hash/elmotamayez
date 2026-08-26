import { render, screen, waitFor } from "@testing-library/react";
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

const TEACHERS = [
  { uuid: "t-1", name: "أ. سامي" },
  { uuid: "t-2", name: "أ. هدى" },
];

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    list: (params: Record<string, string>) => list(params),
    workspaceTeachers: () => Promise.resolve({ data: TEACHERS }),
    generate: () => Promise.resolve({}),
    create: () => Promise.resolve({}),
  },
}));

vi.mock("@/lib/api", () => ({
  api: {
    get: () => Promise.resolve({ data: [{ uuid: "c-1", title: "رياضيات ٣ث" }] }),
  },
  fieldErrors: () => ({}),
}));

beforeEach(() => {
  vi.clearAllMocks();
  list.mockResolvedValue({ data: [] });
});

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
    */
    const picker = document.getElementById("filter_teacher");

    expect(picker).not.toBeNull();
    await userEvent.selectOptions(picker as HTMLSelectElement, "t-2");

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
