import { test, expect, type Page } from "@playwright/test";

import { useTeacherAccount } from "./teacher-account";

/**
 * The route into the grading board, walked the way a teacher walks it.
 *
 * ⚠️ ENTERED FROM THE SIDEBAR, NEVER BY `page.goto` ON THE TARGET. A spec that
 * navigates straight to `/manage/grading` passes just as happily when nothing in
 * the product links there — which is how spec 004's whole player shipped
 * reachable only by typing its address. This screen is where a student's result
 * is held up waiting for a person; a teacher who cannot find it is a class whose
 * papers are never marked.
 *
 * ⚠️ AND THE TWO HALVES SIGN IN AS DIFFERENT PEOPLE. `grading.perform` sits on
 * the teacher role and on no student, so one account cannot answer both
 * questions — and the student half must run as a real signed-in student rather
 * than a signed-out browser, or "the link is absent" is true for the wrong
 * reason on a page that never rendered a sidebar at all.
 *
 * Needs the backend up and migrated.
 */

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

test.describe("لوحة التصحيح — المدرّس", () => {
  test.beforeEach(async ({ page }) => {
    await useTeacherAccount(page);
  });

  test("الشريط الجانبي ← لوحة التصحيح", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: /لوحة التصحيح/ }).click();

    await expect(page).toHaveURL(/\/manage\/grading$/);

    // Scoped to #main: the shell header renders the page title as an h1 too, so
    // an unqualified matcher resolves to two elements and fails on strict mode.
    await expect(
      page.locator("#main").getByRole("heading", { name: "لوحة التصحيح", level: 1 }),
    ).toBeVisible();

    /*
     | One of the three states, and never a blank panel. Which one depends on
     | what the seed happens to hold — a queue, the empty state, or the error box
     | if the API is down — and asserting a specific one would turn this spec
     | into a test of the seeder. What it must never be is nothing at all.
     */
    await expect(
      page
        .locator("#main")
        .getByText(/ورقة بانتظار التصحيح|لا شيء ينتظر التصحيح|تعذّر/)
        .first(),
    ).toBeVisible();

    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);
  });
});

test.describe("لوحة التصحيح — الطالب", () => {
  /*
   | No `useTeacherAccount` here: the six viewport projects already carry the
   | seeded STUDENT's storageState, which is precisely the account this needs.
   */
  test("لا يُعرض للطالب رابطٌ إلى لوحة التصحيح", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    /*
     | ⚠️ THE POSITIVE ASSERTION COMES FIRST, and it is what makes the negative
     | one worth anything. A signed-out browser is redirected to /login and has
     | no sidebar at all, so `toHaveCount(0)` below would pass on a page that
     | never rendered one — the vacuous green that hides a regression rather than
     | catching it.
     */
    await expect(page.getByRole("link", { name: "تعلّمي" })).toBeVisible();

    /*
     | The sidebar shipped ungated once: a student was offered the course editor,
     | the exam builder, the question bank and the settlement statement, and the
     | server refused every one of them on click. A menu of links that answer 403
     | teaches the reader that the product does not know who they are.
     */
    await expect(page.getByRole("link", { name: /لوحة التصحيح/ })).toHaveCount(0);
  });
});
