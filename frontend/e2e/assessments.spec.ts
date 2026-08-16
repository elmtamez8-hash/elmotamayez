import { test, expect, type Page } from "@playwright/test";

import { useTeacherAccount } from "./teacher-account";

/**
 * The four screens spec 008 added that nothing else in the suite opens.
 *
 * ⚠️ THIS FILE COVERS WHAT `grading.spec.ts` AND `assignments.spec.ts` DO NOT —
 * the bank, the item analysis, the mistake notebook and the unlock rules. The
 * three files are deliberately not merged: each of the others is about ONE
 * screen's behaviour, and this one is about REACHABILITY, which is a different
 * failure. A screen you can only open by typing its address is a screen nobody
 * opens, and that is how spec 004's player nearly shipped.
 *
 * ⚠️ EVERY TEST CLICKS THE SIDEBAR RATHER THAN CALLING `goto`. `goto` proves the
 * route renders and says nothing about whether a human could ever get there —
 * which is the whole of the assertion. The `goto('/dashboard')` at the top of
 * each is the starting point, not the subject.
 *
 * Needs the backend up and migrated, seeded with `ScenarioSeeder`.
 */

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

/** No raw error text anywhere on the page. */
async function noRawError(page: Page): Promise<void> {
  await expect(page.locator("body")).not.toContainText(
    /Request failed|Server Error|undefined|\[object Object\]/,
  );
}

test.describe("بنك الأسئلة والتحليل — المدرّس", () => {
  test.beforeEach(async ({ page }) => {
    await useTeacherAccount(page);
  });

  test("الشريط الجانبي ← بنك الأسئلة", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "بنك الأسئلة", exact: true }).click();

    await expect(page).toHaveURL(/\/manage\/bank$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "بنك الأسئلة", level: 1 }),
    ).toBeVisible();

    /*
     * ⚠️ THE CAPTION **OR** THE EMPTY STATE, because `Table` swaps one for the
     * other — it does not render an empty table under its caption, it replaces
     * the whole element with `EmptyState`. Asserting the caption alone passes
     * only on a database that happens to hold questions, which is a test of the
     * seeder wearing the costume of a test of the screen.
     *
     * And never a row COUNT, for the same reason: `ScenarioSeeder` decides how
     * many exist. What must hold is that the screen resolved to one of its
     * declared states rather than to nothing.
     */
    await expect(
      page.locator("#main").getByText(/أسئلة البنك بوسومها|لا أسئلة/),
    ).toBeVisible();

    await noRawError(page);
  });

  test("الشريط الجانبي ← تحليل الأسئلة", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "تحليل الأسئلة", exact: true }).click();

    await expect(page).toHaveURL(/\/manage\/analytics\/questions$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "تحليل الأسئلة", level: 1 }),
    ).toBeVisible();

    /*
     * ⚠️ NOT A PERCENTAGE, AND THIS SCREEN IS NORMALLY EMPTY. The rollup runs
     * NIGHTLY, so a freshly migrated box has nothing computed — and a question
     * only a few students sat stores `wrong_pct = NULL` rather than zero, so
     * even a busy workspace legitimately shows no number. A test demanding one
     * fails against entirely correct behaviour, which is how a screen ends up
     * "fixed" into reporting zeros.
     *
     * Both tables asserted, each in either state: two tables answer two
     * different questions (which QUESTION is failing, which IDEA is), and one
     * of them silently disappearing is a regression a single check would miss.
     */
    await expect(
      page.locator("#main").getByText(/أسئلة البنك مرتّبةً|لا محاولات بعد/),
    ).toBeVisible();
    await expect(
      page.locator("#main").getByText(/نسبة الخطأ لكلّ فكرة|لا أفكار بعد/),
    ).toBeVisible();

    await noRawError(page);
  });

  test("الشريط الجانبي ← شرط فتح الحصة، وفيه القاعدة العامّة", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: /شرط فتح/ }).click();

    await expect(page).toHaveURL(/\/manage\/unlock-rules$/);

    // The screen's whole subject is that two tiers exist and the specific
    // replaces the general — so the general one has to be on it.
    await expect(page.locator("#main").getByText("القاعدة العامّة").first()).toBeVisible();

    await noRawError(page);
  });
});

test.describe("دفتر الأخطاء — الطالب", () => {
  /* No `useTeacherAccount`: the projects already carry the seeded student. */

  test("الشريط الجانبي ← دفتر أخطائي، ولا رابط إلى شاشات المدرّس", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    /*
     * ⚠️ THE POSITIVE ASSERTION FIRST. A signed-out browser is redirected to
     * /login and draws no sidebar at all, so the two negatives below would pass
     * on a page that never rendered a single link — reporting a permission fix
     * that had not happened.
     */
    await page.getByRole("link", { name: "دفتر أخطائي", exact: true }).click();

    await expect(page).toHaveURL(/\/mistakes$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "دفتر أخطائي", level: 1 }),
    ).toBeVisible();

    await openNav(page);

    const nav = page.getByRole("navigation").first();

    await expect(nav.getByRole("link", { name: "بنك الأسئلة", exact: true })).toHaveCount(0);
    await expect(nav.getByRole("link", { name: "تحليل الأسئلة", exact: true })).toHaveCount(0);

    await noRawError(page);
  });

  test("درّب نفسك مدخلٌ مستقلّ عن الدفتر", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    /*
     * ⚠️ TWO ENTRIES ON PURPOSE, AND THIS IS THE TEST OF THAT DECISION.
     * Building a paper starts from the bank; the notebook starts from your own
     * history. A student wanting to revise a topic they have never been tested
     * on would never look for it inside a list of their mistakes — so folding
     * one into the other makes that screen unreachable for the person who needs
     * it, without removing a single route.
     */
    await page.getByRole("link", { name: "درّب نفسك", exact: true }).click();

    await expect(page).toHaveURL(/\/practice$/);
    await noRawError(page);
  });
});
