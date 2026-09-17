import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CourseCurriculum } from "./CourseCurriculum";
import type { CurriculumItem, CurriculumSection } from "@/lib/public-api";

/*
| المنهجُ كما يقرؤُه الزائر: كلُّ عنصرٍ بنوعِه وحالتِه، قبلَ أن يضغطَ شيئاً.
|
| ⚠️ الحالةُ كلمةٌ قبلَ أن تكونَ لوناً أو أيقونة. هذا المستودعُ شحنَ حالةً غيرَ
| مرئيّةٍ أربعَ مرّاتٍ بتسميةِ لونٍ لم يُعرَّفْ في `@theme` — لا يُصدِرُ Tailwind v4
| له قاعدةً إطلاقاً — وفي كلِّ مرّةٍ كانَ الاختبارُ يؤكّدُ على `aria-label` فوقَ
| علامةٍ لم تُطلَ. فالمقياسُ هنا نصٌّ يقرؤُه الإنسانُ وقارئُ الشاشةِ معاً.
*/

function item(overrides: Partial<CurriculumItem> = {}): CurriculumItem {
  return { title: "Lecture recording", kind: "video", ...overrides };
}

function tree(items: CurriculumItem[]): CurriculumSection[] {
  return [{ title: "Uploaded & referenced", chapters: [{ title: "Files", items }] }];
}

describe("CourseCurriculum", () => {
  it("names the type of every item in words, whatever its mark", () => {
    render(
      <CourseCurriculum
        sections={tree([
          item({ title: "Lecture recording", kind: "video" }),
          item({ title: "Handout", kind: "pdf" }),
          item({ title: "Worksheet", kind: "file" }),
          item({ title: "Sit the unit test", kind: "exam" }),
        ])}
      />,
    );

    expect(screen.getByText("فيديو")).toBeTruthy();
    expect(screen.getByText("مستند PDF")).toBeTruthy();
    expect(screen.getByText("ملف")).toBeTruthy();
    expect(screen.getByText("اختبار")).toBeTruthy();
  });

  it("still draws an item whose kind it has never heard of", () => {
    /*
      ⛔ `LessonTypeRegistry` على الخادمِ هو مَن يملكُ المفردات، فنوعٌ يُضافُ هناك
      ويصلُ إلى هنا قبلَ أن يُضافَ للخريطةِ يجبُ أن يظهرَ بعلامةٍ محايدةٍ لا بمربّعٍ
      فارغ. والكلمةُ إلى جانبِه تسقطُ إلى المفتاحِ الخامِّ لا إلى الصمت.
    */
    render(<CourseCurriculum sections={tree([item({ title: "Hologram", kind: "hologram" })])} />);

    expect(screen.getByText("Hologram")).toBeTruthy();
    expect(screen.getByText("hologram")).toBeTruthy();
  });

  it("marks a locked item with a word, and builds no link for it", () => {
    render(<CourseCurriculum sections={tree([item()])} courseSlug="authoring-showcase" />);

    expect(screen.getByText("بعد التسجيل")).toBeTruthy();
    expect(screen.queryAllByRole("link")).toHaveLength(0);
  });

  it("links only the item the server opened, and says it is free", () => {
    /*
      ⛔ الشرطُ هو شكلُ الحمولةِ لا قاعدةٌ تُعادُ كتابتُها هنا (٠٣٢ · FR-019):
      `uuid` و`is_open` غائبانِ عن كلِّ صفٍّ إلّا الدرسَ المُضمَّنَ المفتوح، فلا
      يمكنُ بناءُ رابطٍ لغيرِه ولو بالخطأ.
    */
    render(
      <CourseCurriculum
        sections={tree([
          item({ title: "Welcome", kind: "article", uuid: "l-free", is_open: true }),
          item({ title: "Lecture recording", kind: "video" }),
        ])}
        courseSlug="authoring-showcase"
      />,
    );

    const links = screen.getAllByRole("link");

    expect(links).toHaveLength(1);
    expect(links[0].getAttribute("href")).toBe("/courses/authoring-showcase/lessons/l-free");
    expect(within(links[0]).getByText("مجّانيّة")).toBeTruthy();
  });

  it("says a lesson is free when the teacher opened it, without promising a click", () => {
    /*
      ⛔ العطلُ الذي كُتبَ هذا لأجلِه: فيديو مرفوعٌ وسمَه المدرّسُ «متاح بلا
      تسجيل» كانَ يُرسَمُ «بعد التسجيل» — أي أنّ الوسمَ له قارئٌ عندَ منحِ
      التشغيلِ ولا قارئَ على الصفحةِ التي تبيعُ الكورس.

      والصفُّ **لا يصيرُ رابطاً**: الخادمُ لا يُرسِلُ `uuid` معَ هذا المفتاح،
      فشكلُ البيانات — لا شرطٌ يُعادُ هنا — هو ما يمنعُ بناءَه.
    */
    render(
      <CourseCurriculum
        sections={tree([
          item({ title: "Lecture recording", kind: "video", free_with_account: true }),
        ])}
        courseSlug="authoring-showcase"
      />,
    );

    expect(screen.getByText("مفتوح مجّاناً")).toBeTruthy();
    expect(screen.queryByText("بعد التسجيل")).toBeNull();
    expect(screen.queryAllByRole("link")).toHaveLength(0);
  });

  it("keeps the three states apart on one tree", () => {
    /*
      ثلاثةُ أجوبةٍ لمشترٍ يقرّر: «افتحْه الآن» و«فتحَهُ المدرّسُ مجّاناً»
      و«بعد الشراء». جمعُ الثاني مع الثالثِ هو العطلُ نفسُه بصياغةٍ أخرى.
    */
    render(
      <CourseCurriculum
        sections={tree([
          item({ title: "Welcome", kind: "embed", uuid: "l-open", is_open: true }),
          item({ title: "Unit one", kind: "video", free_with_account: true }),
          item({ title: "Unit two", kind: "video" }),
        ])}
        courseSlug="authoring-showcase"
      />,
    );

    expect(screen.getByText("مجّانيّة")).toBeTruthy();
    expect(screen.getByText("مفتوح مجّاناً")).toBeTruthy();
    expect(screen.getByText("بعد التسجيل")).toBeTruthy();
    expect(screen.getAllByRole("link")).toHaveLength(1);
  });

  it("builds no link at all without a course to link into", () => {
    // المعاينةُ قبلَ النشرِ لا صفحةَ لها، فالرابطُ هناك عنوانٌ لا يفتحُ شيئاً.
    render(
      <CourseCurriculum
        sections={tree([item({ title: "Welcome", uuid: "l-free", is_open: true })])}
      />,
    );

    expect(screen.queryAllByRole("link")).toHaveLength(0);
  });
});
