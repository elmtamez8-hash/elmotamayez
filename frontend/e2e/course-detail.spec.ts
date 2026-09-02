import { test, expect } from "@playwright/test";

/**
 * Spec 023 · SC-001 — one click from a card to the course.
 *
 * ⚠️ ANONYMOUS, DECLARED. Since 002 every project carries an authenticated
 * `storageState` so the panel can be audited, and inheriting it here would test
 * a signed-in visitor while claiming to test the public page — which is exactly
 * the reader this page exists for.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const COURSE_URL = /\/courses\/[0-9a-f-]{36}(\?|$)/;

test.describe("course detail", () => {
  test("a course card opens the course itself, in one click", async ({ page }) => {
    await page.goto("/courses");

    const card = page.locator("article").first();
    await expect(card).toBeVisible();

    const title = (await card.getByRole("heading", { level: 3 }).innerText()).trim();

    // ONE click. Before 023 this took three — card, then the teacher's page,
    // then their courses tab, then the same card again.
    await card.getByRole("link", { name: title }).click();

    await expect(page).toHaveURL(COURSE_URL);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(title);
  });

  test("the course page is readable with JavaScript switched off", async ({ browser }) => {
    // SC-008: a crawler runs no JavaScript, and the page is server-rendered for
    // exactly that reader. A context with JS disabled is the only honest way to
    // ask — a `page.content()` check against a hydrated page proves nothing.
    const context = await browser.newContext({
      javaScriptEnabled: false,
      storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();

    await page.goto("/courses");
    const href = await page.locator("article").first().locator("a").first().getAttribute("href");

    expect(href).toMatch(COURSE_URL);

    await page.goto(href!);

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByRole("heading", { name: "المنهج" })).toBeVisible();

    await context.close();
  });

  test("publishes no link into a lesson a visitor has not bought", async ({ page }) => {
    await page.goto("/courses");
    const href = await page.locator("article").first().locator("a").first().getAttribute("href");
    await page.goto(href!);

    // SC-004. The payload carries no lesson identifier, so there is nothing to
    // build a link from — this asserts the property rather than the payload,
    // because a link could also be invented in the component.
    await expect(page.locator('a[href*="/learn/"]')).toHaveCount(0);
  });

  test("answers a course that does not exist with the not-found page", async ({ page }) => {
    const response = await page.goto("/courses/00000000-0000-4000-8000-000000000000");

    expect(response?.status()).toBe(404);
  });
});
