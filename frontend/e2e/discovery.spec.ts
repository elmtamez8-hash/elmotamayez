import { test, expect } from "@playwright/test";

/**
 * The US1 discovery journey, walked as an anonymous visitor.
 *
 * Requires the backend on :8000 with seeded, published teachers. These assertions
 * are about structure and shareability, not styling.
 */

// Anonymous, as the docblock says. Since 002 the projects carry an
// authenticated storageState for the panel audit, and inheriting it here
// would quietly test a signed-in visitor instead.
test.use({ storageState: { cookies: [], origins: [] } });

const PROFILE_URL = /\/teachers\/[0-9a-f-]{36}/;

test.describe("public discovery", () => {
  test("home page is Arabic and RTL with every section present", async ({ page }) => {
    await page.goto("/");

    const html = page.locator("html");
    await expect(html).toHaveAttribute("lang", "ar");
    await expect(html).toHaveAttribute("dir", "rtl");

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByRole("link", { name: "سجّل كطالب" }).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "سجّل كمدرّس" }).first()).toBeVisible();

    for (const heading of [
      "كيف تعمل المنصة",
      "أفضل المدرّسين",
      "الكورسات المميزة",
      "المواد الدراسية",
      "الأسئلة الشائعة",
    ]) {
      await expect(page.getByRole("heading", { name: heading })).toBeVisible();
    }

    await expect(page.getByRole("contentinfo")).toBeVisible();
  });

  test("never scrolls horizontally at 360px", async ({ page }) => {
    for (const path of ["/", "/teachers"]) {
      await page.goto(path);

      const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      );

      expect(overflows, `${path} scrolls horizontally`).toBe(false);
    }
  });

  test("a subject tile pre-applies its filter on the teachers page", async ({ page }) => {
    await page.goto("/");

    const tile = page.locator('a[href^="/teachers?subject="]').first();
    test.skip((await tile.count()) === 0, "no subjects seeded");

    const href = await tile.getAttribute("href");
    await tile.click();

    await expect(page).toHaveURL(new RegExp(href!.replace("?", "\\?")));
    await expect(page.getByRole("heading", { name: "المدرسون" })).toBeVisible();
  });

  test("filters live in the url so a filtered result is shareable", async ({ page, context }) => {
    await page.goto("/teachers?sort=trust_desc&min_rating=4");

    const rendered = await page.locator("main").innerText();

    // Same url, fresh page: an identical render is what SC-015 asks for.
    const other = await context.newPage();
    await other.goto("/teachers?sort=trust_desc&min_rating=4");

    expect(await other.locator("main").innerText()).toBe(rendered);
  });

  test("shows a guiding empty state instead of a blank grid", async ({ page }) => {
    await page.goto("/teachers?subject=definitely-not-a-real-subject");

    await expect(page.getByText("لا يوجد مدرّسون مطابقون لبحثك")).toBeVisible();
    await expect(page.getByRole("link", { name: /إزالة كل الفلاتر|سجّل كمدرّس/ })).toBeVisible();
  });

  test("profile tabs are shareable and the booking CTA stays reachable", async ({ page }) => {
    await page.goto("/teachers");

    const profileLink = page.locator('a[href^="/teachers/"]').first();
    test.skip((await profileLink.count()) === 0, "no teachers seeded");

    await profileLink.click();
    // Wait for the navigation itself: the dynamic route compiles on demand in dev,
    // and the list page's own h1 stays on screen meanwhile, so asserting content
    // straight away can pass against the page we just left.
    await page.waitForURL(PROFILE_URL);

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByRole("link", { name: "احجز الآن" }).first()).toBeVisible();

    await page.getByRole("link", { name: "الجدول" }).click();
    await expect(page).toHaveURL(/tab=schedule/);

    // Reloading the shared url must land on the same tab, not reset to "about".
    await page.reload();
    await expect(page.getByRole("link", { name: "الجدول" })).toHaveAttribute("aria-current", "page");

    // Scrolled to the bottom, a booking CTA must still be on screen: the sticky
    // side panel on desktop, the fixed bar below lg.
    await page.mouse.wheel(0, 4000);
    await expect(page.getByRole("link", { name: "احجز الآن" }).last()).toBeInViewport();
  });

  test("booking carries the teacher context into signup", async ({ page }) => {
    await page.goto("/teachers");

    const profileLink = page.locator('a[href^="/teachers/"]').first();
    test.skip((await profileLink.count()) === 0, "no teachers seeded");

    await profileLink.click();
    await page.waitForURL(PROFILE_URL);

    const uuid = page.url().split("/teachers/")[1].split("?")[0];

    await page.getByRole("link", { name: "احجز الآن" }).first().click();
    await expect(page).toHaveURL(new RegExp(`/signup/student\\?teacher=${uuid}`));
  });

  test("core content is readable without javascript", async ({ browser }) => {
    // SC-016: a crawler that executes no scripts must still see the listings.
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto("/teachers");

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    const articles = page.locator("article");

    if ((await articles.count()) > 0) {
      await expect(articles.first()).toContainText(/\S/);
    }

    await context.close();
  });
});
