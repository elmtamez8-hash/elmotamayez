import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";

import { CourseTabs, openCourseTab } from "./CourseTabs";

/*
| ثلاثةُ أقسامٍ في شريطٍ واحد — والرابطُ القادمُ من صفحةِ المدرّسِ يجبُ أن يصل.
|
| ⛔ العطبُ الذي يمنعُه هذا الملفّ: `/teachers/{slug}` يُلصِقُ `#groups` بكلِّ
| بطاقةِ كورسٍ يعرضُها (`CourseCard`'s `anchor`)، فالقارئُ يصلُ طالباً المواعيد.
| قبلَ التبويبِ كانت مرساةً تنزلُ بالصفحة؛ وبعدَه صارَ القسمُ خلفَ نقرة — ومرساةٌ
| إلى لوحٍ مخفيٍّ رابطٌ لا يفعلُ شيئاً بصمت، وهو أسوأُ من رابطٍ يذهبُ لمكانٍ خطأ.
*/

function renderTabs(hash = "") {
  window.history.replaceState(null, "", `/courses/x${hash}`);

  return render(
    <CourseTabs
      groupCount={2}
      about={<p>وصفُ الكورس</p>}
      groups={<p>مجموعة السبت</p>}
      privateSession={<p>نموذج الحصة الخاصة</p>}
    />,
  );
}

beforeEach(() => {
  window.history.replaceState(null, "", "/courses/x");
});

describe("CourseTabs", () => {
  it("opens on «عن الكورس» and shows one panel at a time", () => {
    renderTabs();

    expect(screen.getByText("وصفُ الكورس")).toBeTruthy();
    expect(screen.queryByText("مجموعة السبت")).toBeNull();
    expect(screen.queryByText("نموذج الحصة الخاصة")).toBeNull();
  });

  it("opens the groups tab for a reader arriving on #groups", () => {
    renderTabs("#groups");

    // ⚠️ اللوحُ نفسُه لا التبويبُ وحدَه: تبويبٌ مُحدَّدٌ فوقَ لوحٍ فارغٍ يمرُّ
    // على تنفيذٍ يضبطُ الحالةَ ولا يرسمُ شيئاً.
    expect(screen.getByText("مجموعة السبت")).toBeTruthy();
    expect(screen.getByRole("tab", { name: /المجموعات المتاحة/ }).getAttribute("aria-selected")).toBe(
      "true",
    );
  });

  it("does not drag the reader back to the groups tab after they press another", () => {
    /*
      ⚠️ الهاشُ يبقى في شريطِ العنوانِ بعدَ الوصول — `useTabParam` يكتبُ في
      الاستعلامِ لا في الهاش — فقراءتُه في أثرٍ حيٍّ تُعيدُ القارئَ إلى
      «المجموعات» كلّما ضغطَ تبويباً آخر. يُقرَأُ مرّةً عندَ الوصولِ فقط.
    */
    renderTabs("#groups");

    fireEvent.click(screen.getByRole("tab", { name: /حصة خاصة/ }));

    expect(screen.getByText("نموذج الحصة الخاصة")).toBeTruthy();
    expect(screen.queryByText("مجموعة السبت")).toBeNull();
  });

  it("drops the «عن الكورس» tab entirely when there is no description", () => {
    // تبويبٌ فارغٌ وعدٌ بمحتوًى لا وجودَ له، وأوّلُ ما يُفتَحُ يصيرُ المجموعات.
    window.history.replaceState(null, "", "/courses/x");

    render(
      <CourseTabs
        groupCount={0}
        about={null}
        groups={<p>مجموعة السبت</p>}
        privateSession={<p>نموذج الحصة الخاصة</p>}
      />,
    );

    expect(screen.queryByRole("tab", { name: /عن الكورس/ })).toBeNull();
    expect(screen.getByText("مجموعة السبت")).toBeTruthy();
  });

  it("opens the groups tab when the rail asks for it, after arrival", () => {
    /*
      ⚠️ «اختر مجموعتك» on the rail sits on the same page, so neither `?tab=`
      nor `#groups` can reach this strip once it has mounted — both are read on
      arrival only. The event is the door between them.
    */
    renderTabs();
    expect(screen.queryByText("مجموعة السبت")).toBeNull();

    act(() => openCourseTab("groups"));

    expect(screen.getByText("مجموعة السبت")).toBeTruthy();
    expect(screen.getByRole("tab", { name: /المجموعات المتاحة/ }).getAttribute("aria-selected")).toBe(
      "true",
    );
  });

  it("ignores a request for a tab it does not have", () => {
    renderTabs();

    act(() => openCourseTab("nonsense"));

    expect(screen.getByText("وصفُ الكورس")).toBeTruthy();
  });

  it("prints no badge when the course has no groups", () => {
    render(
      <CourseTabs
        groupCount={0}
        about={<p>وصفُ الكورس</p>}
        groups={<p>لا مواعيد</p>}
        privateSession={<p>نموذج</p>}
      />,
    );

    const tab = screen.getByRole("tab", { name: /المجموعات المتاحة/ });

    // «٠» بجوارِ اسمِ التبويبِ عددٌ يُقرَأُ عطلاً؛ الغيابُ هو الجواب.
    expect(tab.textContent).not.toContain("0");
  });
});
