import { test, expect } from "@playwright/test";

/**
 * US4 — the four-step application, walked as a new applicant.
 *
 * Each run registers a fresh email so the wizard starts clean; the backend
 * rate-limits step 1, so this spec submits it once per test at most.
 */

// An applicant is by definition signed out. Since 002 the projects carry an
// authenticated storageState so the panel can be audited, and the wizard reads
// `auth_token` to decide whether to create an account or attach to the current
// one — inheriting that token silently sends this spec down the wrong branch.
test.use({ storageState: { cookies: [], origins: [] } });

/** Unique per project *and* per millisecond: six browser projects start at once
 * and Date.now() alone collides, which the unique-email rule then rejects. */
function freshEmail(): string {
  return `teacher-${test.info().project.name}-${Date.now()}@example.com`;
}

test.describe("teacher application wizard", () => {
  test("shows a four-step progress indicator starting at step 1", async ({ page }) => {
    await page.goto("/signup/teacher");

    const progress = page.getByRole("list", { name: /الخطوة 1 من 4/ });
    await expect(progress).toBeVisible();

    for (const label of ["بيانات أساسية", "بيانات مهنية", "المستندات", "السعر والتوفّر"]) {
      await expect(progress.getByText(label)).toBeVisible();
    }
  });

  // FR-071 stated in the UI, not only enforced by the API: the applicant has to
  // know no document is being collected here.
  test("the documents step offers no file input at all", async ({ page }, testInfo) => {
    // One project only. Step 1 is rate-limited per IP — correctly, it creates
    // accounts — and six browser projects registering in parallel is not a user,
    // it is the thing the limit exists to stop. Layout across widths and schemes
    // is covered by the tests that do not POST.
    test.skip(testInfo.project.name !== "desktop-light", "registers an account");

    await page.goto("/signup/teacher");

    await page.getByLabel("الاسم الأول").fill("خالد");
    await page.getByLabel("البريد الإلكتروني").fill(freshEmail());
    await page.getByLabel("رقم الجوال", { exact: true }).fill("55598765");
    await page.getByLabel("كلمة المرور", { exact: true }).fill("password123");
    await page.getByLabel("تأكيد كلمة المرور").fill("password123");
    await page.getByRole("checkbox", { name: /الشروط والأحكام/ }).check();
    await page.getByRole("button", { name: "التالي" }).click();

    // Step 2
    await expect(page.getByText("المواد التي تدرّسها")).toBeVisible();
    await page.getByText("الرياضيات", { exact: true }).first().click();
    await page.getByText("المرحلة الثانوية", { exact: true }).first().click();
    await page.getByLabel("العنوان التعريفي").fill("مدرّس رياضيات");
    await page.getByRole("button", { name: "التالي" }).click();

    // Step 3
    await expect(page.getByText("المستندات المطلوبة للتحقق")).toBeVisible();
    await expect(page.locator('input[type="file"]')).toHaveCount(0);
    await expect(page.getByText("لا تُرفع أي مستندات في هذه الخطوة")).toBeVisible();
  });

  test("requires the terms checkbox on step 1", async ({ page }) => {
    await page.goto("/signup/teacher");

    await page.getByLabel("الاسم الأول").fill("خالد");
    await page.getByLabel("البريد الإلكتروني").fill(freshEmail());
    await page.getByLabel("رقم الجوال", { exact: true }).fill("55598765");
    await page.getByLabel("كلمة المرور", { exact: true }).fill("password123");
    await page.getByLabel("تأكيد كلمة المرور").fill("password123");

    await page.getByRole("button", { name: "التالي" }).click();
    await expect(page.getByText("يجب الموافقة على الشروط والأحكام")).toBeVisible();
  });

  test("the confirmation page states a concrete review time", async ({ page }) => {
    await page.goto("/signup/teacher/submitted");

    await expect(
      page.getByRole("heading", { name: "طلبك قيد المراجعة من فريقنا الأكاديمي" }),
    ).toBeVisible();
    await expect(page.getByText(/خلال 3 أيام عمل/)).toBeVisible();
  });
});
