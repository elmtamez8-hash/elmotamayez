import { test, expect, type Page } from "@playwright/test";

import { useTeacherAccount } from "./teacher-account";

/**
 * The two routes into homework, walked the way each person walks them.
 *
 * ⚠️ TWO ENTRIES, NOT ONE, AND THAT IS THE THING UNDER TEST. «واجباتي» is what a
 * student owes; «الواجبات» is what a teacher set and has to mark. One shared link
 * whose meaning flipped with the reader's permission is the shape that put the
 * exam builder in a student's sidebar — so each half asserts its own link is
 * present and the other's is not.
 *
 * Needs the backend up and migrated.
 */

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

test.describe("الواجبات — المدرّس", () => {
  test.beforeEach(async ({ page }) => {
    await useTeacherAccount(page);
  });

  test("الشريط الجانبي ← الواجبات", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "الواجبات", exact: true }).click();

    await expect(page).toHaveURL(/\/manage\/assignments$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "الواجبات", level: 1 }),
    ).toBeVisible();

    // One of the three states and never a blank panel. Which one depends on the
    // seed, and asserting a particular one would make this a test of the seeder.
    await expect(
      page.locator("#main").getByText(/مسوّدة|منشور|لا واجبات بعد|تعذّر/).first(),
    ).toBeVisible();

    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);
  });
});

test.describe("الواجبات — الطالب", () => {
  /* No `useTeacherAccount`: the projects already carry the seeded student. */

  test("الشريط الجانبي ← واجباتي، ولا رابط إلى واجبات المدرّس", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    // ⚠️ The positive assertion first — a signed-out browser is redirected to
    // /login and renders no sidebar, so the negative below would pass on a page
    // that never drew one.
    await page.getByRole("link", { name: "واجباتي" }).click();

    await expect(page).toHaveURL(/\/assignments$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "واجباتي", level: 1 }),
    ).toBeVisible();

    await expect(
      page.locator("#main").getByText(/لا واجبات عليك الآن|من .* درجة|تعذّر/).first(),
    ).toBeVisible();

    await openNav(page);
    await expect(page.getByRole("link", { name: "الواجبات", exact: true })).toHaveCount(0);
  });
});
