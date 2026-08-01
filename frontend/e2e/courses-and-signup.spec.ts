import { test, expect } from "@playwright/test";

/**
 * US3 (course discovery) and US2 (student signup), walked as an anonymous
 * visitor. Requires the backend on :8000 with the marketplace demo seeded.
 */

test.describe("course discovery", () => {
  test("lists courses and keeps the type filter in the URL", async ({ page }) => {
    await page.goto("/courses");

    await expect(page.getByRole("heading", { level: 1, name: "الكورسات" })).toBeVisible();

    const cards = page.getByRole("article");
    await expect(cards.first()).toBeVisible();

    await page.getByLabel("نوع الكورس").selectOption("recorded");
    await expect(page).toHaveURL(/type=recorded/);

    // Shareable: the same URL must reproduce the same filtered view (FR-051).
    await page.reload();
    await expect(page.getByLabel("نوع الكورس")).toHaveValue("recorded");
  });

  test("shows an empty state rather than an error when nothing matches", async ({ page }) => {
    await page.goto("/courses?subject=definitely-not-a-subject");

    await expect(page.getByText("لا توجد كورسات مطابقة لبحثك")).toBeVisible();
    await expect(page.getByRole("link", { name: "إزالة كل الفلاتر" })).toBeVisible();
  });

  test("renders course cards without JavaScript", async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto("/courses");
    await expect(page.getByRole("article").first()).toBeVisible();

    await context.close();
  });
});

test.describe("student signup", () => {
  test("requires the terms checkbox before it will submit", async ({ page }) => {
    await page.goto("/signup/student");

    await page.getByLabel("الاسم الأول").fill("سارة");
    await page.getByLabel("البريد الإلكتروني").fill(`sara-${test.info().project.name}-${Date.now()}@example.com`);
    await page.getByLabel("رقم الجوال", { exact: true }).fill("55512345");
    await page.getByLabel("كلمة المرور", { exact: true }).fill("password123");
    await page.getByLabel("تأكيد كلمة المرور").fill("password123");

    // FR-065: ships unchecked.
    const terms = page.getByRole("checkbox", { name: /الشروط والأحكام/ });
    await expect(terms).not.toBeChecked();

    await page.getByRole("button", { name: "إنشاء حساب طالب" }).click();
    await expect(page.getByText("يجب الموافقة على الشروط والأحكام")).toBeVisible();
  });

  test("reveals the parent note the moment the toggle flips (FR-064)", async ({ page }) => {
    await page.goto("/signup/student");

    const note = page.getByText("الحساب سيُنشأ باسم الطالب");
    await expect(note).toBeHidden();

    await page.getByRole("switch", { name: "التسجيل بواسطة وليّ الأمر" }).check();
    await expect(note).toBeVisible();
  });

  test("carries the booking intent through from a teacher profile", async ({ page }) => {
    await page.goto("/teachers");
    const href = await page.locator('a[href^="/teachers/"]').first().getAttribute("href");

    await page.goto(`${href}`);
    await page.getByRole("link", { name: "احجز الآن" }).first().click();

    await expect(page).toHaveURL(/\/signup\/student\?teacher=/);
    await expect(page.getByText("ستعود لصفحة المدرّس لإتمام الحجز")).toBeVisible();
  });
});
