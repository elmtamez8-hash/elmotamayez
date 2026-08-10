import { test, expect, type Page } from "@playwright/test";

/**
 * The routes into the credit screens, walked the way a person walks them.
 *
 * Entered FROM the sidebar, never by `page.goto` on the target: a spec that
 * navigates straight to `/billing` passes just as happily when nothing in the
 * product links there — which is how spec 004's whole player shipped reachable
 * only by typing its address.
 *
 * ⚠️ AND WHAT IS ASSERTED IS THAT NO MONEY IS ON THE SCREEN. A credit's price is
 * the teacher's approved settlement rate plus two platform constants, so a total
 * shown to a student is solvable for every teacher's rate across two package
 * sizes (FR-021ج). The API sends no amount; this is the check that no component
 * invents one.
 *
 * Runs signed in as the seeded student, so it needs the backend up and seeded.
 */

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

/** Any currency on screen, in either script. */
const MONEY = /ر\.ق|QAR|ريال/;

test.describe("الوصول إلى الأرصدة", () => {
  test("الشريط الجانبي ← رصيدي", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "رصيدي" }).click();

    await expect(page).toHaveURL(/\/billing$/);
    // Scoped to #main: the shell header renders the page title as an h1 too, so
    // an unqualified matcher resolves to two elements and fails on strict mode.
    await expect(
      page.locator("#main").getByRole("heading", { name: "رصيدي", level: 1 }),
    ).toBeVisible();

    // Scoped to #main, like every text assertion below it: the sidebar carries
    // the same words as a link, and on a phone that link is HIDDEN once the
    // drawer closes — so an unscoped `.first()` resolves to it and the
    // assertion fails on a page that rendered perfectly.
    await expect(
      page.locator("#main").getByText(/الأرصدة|لا رصيد لك بعد|تعذّر تحميل الأرصدة/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);

    // The whole point of the screen's design, asserted rather than assumed.
    await expect(page.locator("main")).not.toContainText(MONEY);
  });

  test("الشريط الجانبي ← أرصدة الطلاب، بلا رقم مال", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "أرصدة الطلاب" }).click();

    await expect(page).toHaveURL(/\/manage\/billing\/students$/);
    // "تعذّر" is a real answer here, not a failure: this screen is behind
    // BILLING_BALANCE_VIEW and the seeded student does not hold it, so what a
    // student must see is the stated error state — never a raw one.
    await expect(
      page.locator("#main").getByText(/أرصدة الطلاب|لا طلاب|تعذّر/).first(),
    ).toBeVisible();

    // A teacher who could read what a student paid would solve for the
    // platform's margin from any two rows — which is why the API sends credits
    // and nothing else.
    await expect(page.locator("main")).not.toContainText(MONEY);
  });

  test("الشريط الجانبي ← وضع الامتحانات، والحالة معروضة لا مخمَّنة", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "وضع الامتحانات" }).click();

    await expect(page).toHaveURL(/\/manage\/billing\/exam-mode$/);

    // One of the two states, and both are real answers: a window in force, or
    // the form to open one. There is no third "unknown" state, because there is
    // no stored flag to be out of step with.
    await expect(
      page.locator("#main").getByText(/وضع الامتحانات مفعَّل الآن|تفعيل الوضع|تعذّر/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);
  });
});
