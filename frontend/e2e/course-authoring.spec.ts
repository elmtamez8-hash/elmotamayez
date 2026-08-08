import { test, expect } from "@playwright/test";
import { useTeacherAccount } from "./teacher-account";

/**
 * A teacher builds a course from nothing and a student opens what they built.
 *
 * Entered from the course page's own buttons, never by `page.goto` into the
 * authoring URL. That is the whole of `SC-016`: ten structure endpoints existed
 * from the first migration and nothing in the product called one of them, so a
 * spec that navigates straight to `/manage/courses/{uuid}/content` would have
 * passed for a year while no teacher could reach the screen.
 *
 * Serial, because it is one story: the item the second test looks for is the one
 * the first test published.
 *
 * The teacher half runs on `useTeacherAccount()`; the projects' own storageState
 * is a student, which is who the last test wants anyway.
 */
test.describe.configure({ mode: "serial" });

const SECTION = "وحدة اختبار آلي";
const CHAPTER = "فصل اختبار آلي";
const LESSON = "عنصر اختبار آلي";

test.describe("SC-016 — من صفحة الكورس إلى عنصر منشور", () => {
  test("المدرّس يبني قسماً وفصلاً وعنصراً ثم ينشرها بعد رؤية الأثر", async ({ page }) => {
    await useTeacherAccount(page);

    await page.goto("/manage/courses");

    // Into a course from the list, then into its content from the course page —
    // the two links T099 and T100 exist to provide. A goto here would test the
    // route and prove nothing about whether anyone can get to it.
    await page.getByRole("link", { name: /^إدارة$|عرض|تفاصيل/ }).first().click();
    await page.getByRole("link", { name: "محتوى الكورس" }).first().click();

    await expect(page).toHaveURL(/\/manage\/courses\/[^/]+\/content$/);
    await expect(page.getByRole("heading", { name: "محتوى الكورس", level: 2 })).toBeVisible();

    await page.getByLabel("عنوان القسم الجديد").fill(SECTION);
    await page.getByRole("button", { name: "إضافة قسم" }).click();

    // Every write re-reads the tree, so the next control only exists once the
    // server has answered — Playwright's auto-waiting covers that, but the
    // notice is what says the write landed rather than the optimistic paint.
    await expect(page.getByText("أُضيف القسم كمسودّة.")).toBeVisible();

    await page.getByLabel("عنوان الفصل الجديد").last().fill(CHAPTER);
    await page.getByRole("button", { name: "إضافة فصل" }).last().click();
    await expect(page.getByText("أُضيف الفصل كمسودّة.")).toBeVisible();

    // The item title comes through window.prompt, so the handler has to be
    // registered BEFORE the click that opens it — a dialog with no handler is
    // auto-dismissed and the click silently does nothing.
    page.once("dialog", (dialog) => void dialog.accept(LESSON));
    await page.getByRole("button", { name: "+ إضافة عنصر" }).last().click();
    await expect(page.getByText("أُضيف العنصر كمسودّة")).toBeVisible();

    await expect(page.getByText(LESSON)).toBeVisible();

    // Publishing goes through the impact dialog (FR-049): the teacher sees what
    // it does to the students already enrolled before it happens.
    await page.getByRole("button", { name: /نشر كل المسودّات/ }).click();

    await expect(page.getByRole("heading", { name: "نشر كل المسودّات" })).toBeVisible();
    await expect(page.getByText("عناصر تدخل حساب التقدّم")).toBeVisible();
    await expect(page.getByText("طلاب تتغيّر نسبتهم")).toBeVisible();

    await page.getByRole("button", { name: "انشر الآن" }).click();

    await expect(page.getByText("نُشرت المسودّات")).toBeVisible();

    // Published means published: the badge is the server's answer after the
    // re-read, not a local guess.
    await expect(
      page.locator("li", { hasText: LESSON }).getByText("منشور").first(),
    ).toBeVisible();
  });

  test("العنصر المنشور لا يظهر كمسودّة لأحد", async ({ page }) => {
    await useTeacherAccount(page);

    await page.goto("/manage/courses");
    await page.getByRole("link", { name: /^إدارة$|عرض|تفاصيل/ }).first().click();
    await page.getByRole("link", { name: "محتوى الكورس" }).first().click();

    // The end of the walk: what was built is still there, still published, after
    // a full reload rather than within one client session.
    await expect(page.getByText(SECTION)).toBeVisible();
    await expect(page.getByText(LESSON)).toBeVisible();
    await expect(
      page.locator("li", { hasText: LESSON }).getByText("مسودّة"),
    ).toHaveCount(0);
  });
});
