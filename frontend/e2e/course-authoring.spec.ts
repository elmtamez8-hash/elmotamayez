import { test, expect } from "@playwright/test";
import { useTeacherAccount } from "./teacher-account";

/**
 * A teacher builds a course from nothing and publishes an item into it.
 *
 * Entered from the product's own links, never by `page.goto` into an authoring
 * URL. That is the whole of `SC-016`: ten structure endpoints existed from the
 * first migration and nothing called one of them, so a spec that navigated
 * straight to `/manage/courses/{uuid}/content` would have passed for a year
 * while no teacher could reach the screen.
 *
 * **Each project creates its own course**, and that is not tidiness. Six browser
 * projects run at once against one backend, and "نشر كل المسودّات" publishes
 * every draft in the course it is on — so sharing a seeded course would have the
 * projects publishing each other's half-built trees and 409ing on
 * `structure_version`, which is the concurrency guard doing exactly its job at
 * the worst possible moment.
 *
 * Serial within a project, because it is one story: the item the second test
 * looks for is the one the first test published.
 */
test.describe.configure({ mode: "serial" });

/**
 * Longer than the default budget, and legitimately so: this is the only spec in
 * the suite that creates a course, three nodes, writes a body and publishes —
 * about a dozen round trips. It fits the 30s default when run alone, and does
 * not when six browser projects are hitting one PHP server at once, which is how
 * the suite actually runs.
 */
test.slow();

/**
 * One stamp for the whole file, so both tests name the same course and two runs
 * on the same database do not collide on `courses.slug` — which is unique, and
 * which the title is derived into.
 */
const RUN = Date.now().toString(36);

test.describe("SC-016 — من صفحة الكورس إلى عنصر منشور", () => {
  test("المدرّس يبني كورساً وقسماً وفصلاً وعنصراً ثم ينشرها بعد رؤية الأثر", async ({
    page,
  }, testInfo) => {
    const tag = `${testInfo.project.name}-${RUN}`;
    const course = `كورس تأليف آلي — ${tag}`;
    const section = `وحدة ${tag}`;
    const chapter = `فصل ${tag}`;
    const lesson = `عنصر ${tag}`;

    await useTeacherAccount(page);

    await page.goto("/manage/courses");
    await page.getByRole("link", { name: "كورس جديد" }).first().click();

    await page.getByLabel("عنوان الكورس").fill(course);
    await page.getByLabel("الوصف").fill("كورس ينشئه اختبار آلي ثم يؤلّف محتواه.");
    await page.getByRole("button", { name: "أنشئ الكورس" }).click();

    // Straight from creating a course to authoring it — the button T100 added.
    await page.getByRole("link", { name: "محتوى الكورس" }).first().click();

    await expect(page).toHaveURL(/\/manage\/courses\/[^/]+\/content$/);
    await expect(page.getByRole("heading", { name: "محتوى الكورس", level: 2 })).toBeVisible();

    await page.getByLabel("عنوان القسم الجديد").fill(section);
    await page.getByRole("button", { name: "إضافة قسم" }).click();

    // Every write re-reads the tree, so the next control only exists once the
    // server has answered. The notice is what says the write LANDED, rather than
    // that an optimistic paint happened.
    await expect(page.getByText("أُضيف القسم كمسودّة.")).toBeVisible();

    await page.getByLabel("عنوان الفصل الجديد").fill(chapter);
    await page.getByRole("button", { name: "إضافة فصل" }).click();
    await expect(page.getByText("أُضيف الفصل كمسودّة.")).toBeVisible();

    // The item title comes through `window.prompt`, so the handler must be
    // registered BEFORE the click that opens it — an unhandled dialog is
    // auto-dismissed and the click then does nothing, silently.
    page.once("dialog", (dialog) => void dialog.accept(lesson));
    await page.getByRole("button", { name: "+ إضافة عنصر" }).click();
    await expect(page.getByText("أُضيف العنصر كمسودّة")).toBeVisible();

    await expect(page.getByText(lesson)).toBeVisible();

    // The body has to be written before the item can be published — required
    // fields are enforced at PUBLISH and never at save (`FR-022`), which is what
    // makes a draft a draft. Skipping this step is not a shortcut: the preview
    // refuses the batch with "النصّ مطلوب", exactly as the publish would.
    await page.getByRole("button", { name: "تحرير" }).click();
    await page.getByLabel("نصّ المقالة").fill("نصّ كتبه اختبار آلي.");
    await page.getByRole("button", { name: "حفظ", exact: true }).click();
    await expect(page.getByText("حُفظ العنصر.")).toBeVisible();
    await page.getByRole("button", { name: "إغلاق" }).click();

    // Publishing goes through the impact dialog (FR-049): the teacher is shown
    // what it does to the students already enrolled before it happens.
    await page.getByRole("button", { name: /نشر كل المسودّات/ }).click();

    await expect(page.getByRole("heading", { name: "نشر كل المسودّات" })).toBeVisible();
    await expect(page.getByText("عناصر تدخل حساب التقدّم")).toBeVisible();
    await expect(page.getByText("طلاب تتغيّر نسبتهم")).toBeVisible();

    await page.getByRole("button", { name: "انشر الآن" }).click();
    await expect(page.getByText("نُشرت المسودّات")).toBeVisible();

    // `exact: true`, because the page's own subtitle says "كل ما تضيفه يبدأ
    // مسودّة…" in prose — a substring match can never reach zero, so the
    // assertion would have been unfailable rather than passing.
    await expect(page.getByText("مسودّة", { exact: true })).toHaveCount(0);
  });

  test("ما نُشر يبقى منشوراً بعد إعادة تحميل الصفحة", async ({ page }, testInfo) => {
    const tag = `${testInfo.project.name}-${RUN}`;

    await useTeacherAccount(page);

    await page.goto("/manage/courses");

    // Back in through the list, by the course this project created — a fresh
    // load rather than the client state the first test left behind.
    await page.getByRole("link", { name: new RegExp(`كورس تأليف آلي — ${tag}`) }).click();
    await page.getByRole("link", { name: "محتوى الكورس" }).first().click();

    await expect(page.getByText(`وحدة ${tag}`)).toBeVisible();
    await expect(page.getByText(`عنصر ${tag}`)).toBeVisible();
    await expect(page.getByText("مسودّة", { exact: true })).toHaveCount(0);
  });
});
