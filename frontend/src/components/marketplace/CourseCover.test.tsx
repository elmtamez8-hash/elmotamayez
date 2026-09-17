import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CourseCover } from "./CourseCover";

/*
| ⛔ ما كانَ هنا قبلَ هذا المكوِّنِ حرفٌ واحدٌ من العنوانِ فوقَ لونٍ مصمَت، بحجمَينِ
| وبلونَينِ في ملفَّينِ — وصفحةٌ مليئةٌ به تُقرَأُ «بياناتٌ لم تُحمَّل» لا «لا غلافَ
| لهذا الكورس».
|
| ⚠️ والتوكيداتُ على نصٍّ يقرؤُه الإنسان، لا على أيقونةٍ مرسومة. هذا المستودعُ
| شحنَ أربعَ مرّاتٍ علامةً غيرَ مطليّةٍ (اسمُ لونٍ لا وجودَ له في `@theme`) واختبارُها
| أخضرُ فوقَها — فالعلامةُ تُقاسُ بوجودِ `svg` وحده، والمعنى يُقاسُ بالكلمة.
*/
const SUBJECT = { slug: "math", name: "الرياضيات", icon: null };

function panel(container: HTMLElement): HTMLElement | null {
  return container.querySelector<HTMLElement>("[aria-hidden='true']");
}

describe("CourseCover", () => {
  it("shows the uploaded cover and generates nothing beside it", () => {
    const { container } = render(
      <CourseCover
        title="أساسيّات التفاضل"
        coverUrl="https://example.test/storage/courses/cover.jpg"
        subject={SUBJECT}
        variant="card"
      />,
    );

    const image = container.querySelector("img");

    expect(image?.getAttribute("src")).toBe("https://example.test/storage/courses/cover.jpg");
    // ⚠️ الغلافُ المرفوعُ يحلُّ محلَّ المولَّدِ ولا يُرسَمُ فوقَه: اسمُ المادّةِ
    // على صورةِ المدرّسِ نصٌّ على خلفيّةٍ لا يعرفُها أحد.
    expect(screen.queryByText("الرياضيات")).toBeNull();
  });

  it("names the subject where there is no uploaded cover", () => {
    const { container } = render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={SUBJECT} variant="card" />,
    );

    expect(screen.getByText("الرياضيات")).toBeTruthy();
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("svg")).toBeTruthy();
  });

  it("still draws a mark for a course nobody has filed under a subject", () => {
    /*
      `courses.subject_id` قابلٌ للفراغ، و«بلا مادّة» كورسٌ لم يُصنَّفْ بعدُ لا
      كورسٌ فارغ. صندوقٌ أبيضُ هنا هو بعينِه العطبُ الذي جاءَ هذا المكوِّنُ لأجلِه.
    */
    const { container } = render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={null} variant="card" />,
    );

    expect(container.querySelector("svg")).toBeTruthy();
    expect(panel(container)).toBeTruthy();
  });

  it("asks the shared resolver for the mark rather than drawing one mark for everything", () => {
    /*
      ⛔ `subjectIcon()` تقرأُ السَّبيكةَ **ورافعةَ المشغِّلِ** `subjects.icon`،
      وملفُّها موجودٌ لأنّ خريطةً ثانيةً منسوخةً بجوارِه ترسمُ علامةَ الفيزياءِ
      على شبكةِ المواد وقبّعةَ تخرّجٍ هنا، بلا خطأٍ في أيِّ مكان.

      ⚠️ والتوكيدُ على **اختلافِ** الرسمَين لا على اسمِ أيقونة: jsdom لا يحملُ
      اسمَ المكوِّنِ إلى الـ`svg`، فأيُّ توكيدٍ يدّعي «هذه أيقونةُ الرياضيات»
      يقيسُ شيئاً غيرَ الذي يقول. وحُذِفَ المُنادى فِعلاً فحمرَّت هذه وحدَها.
    */
    const filed = render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={SUBJECT} variant="card" />,
    ).container.querySelector("svg")?.innerHTML;

    const unfiled = render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={null} variant="card" />,
    ).container.querySelector("svg")?.innerHTML;

    expect(filed).toBeTruthy();
    expect(filed).not.toBe(unfiled);
  });

  it("keeps the generated panel out of the accessibility tree", () => {
    /*
      ⚠️ كلُّ ما يرسمُه المولَّدُ مكتوبٌ نصّاً تحتَه مباشرةً على السطحَين — اسمُ
      المادّةِ في الكارت، والعنوانُ في `h1` تحتَ الشريط. فإعلانُه يقرأُ الكورسَ
      مرّتَين.
    */
    const { container } = render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={SUBJECT} variant="card" />,
    );

    expect(panel(container)?.getAttribute("aria-hidden")).toBe("true");
  });

  it("carries the course itself on the wide band, and drops the subject there", () => {
    /*
      الشريطُ بعرضِ الصفحةِ ولا شيءَ فيه غيرُ علامةٍ يقرأُ فراغاً؛ وشريحةُ المادّةِ
      مرسومةٌ تحتَ العنوانِ في تلك الصفحةِ بالفعل، فسطرانِ متطابقانِ بحجمَين.
    */
    render(
      <CourseCover title="أساسيّات التفاضل" coverUrl={null} subject={SUBJECT} variant="hero" />,
    );

    expect(screen.getByText("أساسيّات التفاضل")).toBeTruthy();
    expect(screen.queryByText("الرياضيات")).toBeNull();
  });
});
