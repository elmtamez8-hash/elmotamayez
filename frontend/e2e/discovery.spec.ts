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

// ⚠️ A SLUG NOW, NOT A UUID. The public profile URL became `/teachers/{slug}`
// and the uuid still resolves but 308s here, so a uuid-shaped pattern matched
// nothing and every test waiting on it timed out. Excludes `/` and `?` so it
// cannot accidentally match the LIST page we navigated from.
const PROFILE_URL = /\/teachers\/[^/?]+(\?|$)/;

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

  test("لوحة الحجز وحدها في عمودها، فتلصق فعلاً ولا يتراكب فوقها شيء", async ({
    page,
  }, testInfo) => {
    /*
     * Two bugs, one column, and each hid the other — which is why this asserts
     * the arrangement and not just the rectangles.
     *
     * FIRST: the trust breakdown was the sticky panel's NEXT SIBLING, so on the
     * way past it — later in the DOM, therefore painted on top — slid straight
     * over the booking CTA. A z-index would have swapped which one was buried,
     * not stopped the burying. The fix was to stick the PAIR.
     *
     * SECOND, caused by that fix and invisible for months: two cards made this
     * column 823px, TALLER than the tabs beside it. `position: sticky` travels
     * inside its containing block, so a box that fills its column moves zero
     * pixels — it read as sticky, scrolled away, and at the bottom of every
     * desktop profile the only booking CTA was 338px above the fold. FR-054 asks
     * for a button that STAYS VISIBLE while scrolling.
     *
     * So the guard is: the breakdown is not in this column at all, and the panel
     * is genuinely pinned mid-scroll. With nothing after the card in the column,
     * the first bug cannot return; with the column shorter than the tabs, nor can
     * the second.
     */
    test.skip(
      (testInfo.project.use.viewport?.width ?? 1440) < 1024,
      "the panels stack below lg, where there is no sticky and nothing to overlap",
    );

    await page.goto("/teachers");

    const profileLink = page.locator('a[href^="/teachers/"]').first();
    test.skip((await profileLink.count()) === 0, "no teachers seeded");

    await profileLink.click();
    await page.waitForURL(PROFILE_URL);

    const panel = page.locator("aside").getByText("الحجز مع").locator("..");
    await expect(panel).toBeVisible();

    // Nothing follows the booking card in its column. This is the arrangement,
    // not a symptom of it: an overlap needs a later sibling to do the covering.
    await expect(page.locator("aside").getByText(/درجة الثقة/)).toHaveCount(0);

    const before = await panel.boundingBox();

    await page.mouse.wheel(0, 900);
    // One frame for the sticky to settle before the rectangles are read.
    await page.waitForTimeout(300);

    const after = await panel.boundingBox();

    expect(before).not.toBeNull();
    expect(after).not.toBeNull();

    // Pinned, which is not the same as motionless: the panel starts below the
    // fold, rises with the page until it reaches its `top-24` offset, and stops
    // there. Measured here: 391 → 96, so 295 of the 900 scrolled. The bug this
    // rules out is a box with no travel room, which absorbs none of the scroll
    // and goes the full 900 — to −509, off the top of the screen for good.
    expect(before!.y - after!.y).toBeLessThan(900);
    await expect(panel).toBeInViewport();
  });

  test("booking carries the teacher context into signup", async ({ page }) => {
    await page.goto("/teachers");

    const profileLink = page.locator('a[href^="/teachers/"]').first();
    test.skip((await profileLink.count()) === 0, "no teachers seeded");

    await profileLink.click();
    await page.waitForURL(PROFILE_URL);

    // Read the uuid off the CTA, not off the address bar. The URL carries the
    // SLUG now; signup stays keyed by the uuid, which is the whole reason both
    // exist — a write path must not depend on a value the teacher can rename.
    const cta = page.getByRole("link", { name: "احجز الآن" }).first();
    const href = (await cta.getAttribute("href")) ?? "";
    const uuid = new URL(href, page.url()).searchParams.get("teacher");

    expect(uuid).toMatch(/^[0-9a-f-]{36}$/);

    await cta.click();
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
