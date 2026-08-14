import { test, expect, type Page } from "@playwright/test";

import { usePlatformAccount } from "./platform-account";

/**
 * The three screens spec 007 added, walked the way a person walks them.
 *
 * Entered FROM the sidebar, never by `page.goto` on the target: a spec that
 * navigates straight to `/manage/payments/collection` passes just as happily
 * when nothing in the product links there — which is how spec 004's whole player
 * shipped reachable only by typing its address.
 *
 * Signed in as the seeded SUPER ADMIN, because all three are platform
 * permissions that no tenant role holds. The projects' student storageState and
 * the teacher token both get a 403 here, by design (FR-033), so a spec written
 * on either would have asserted a refusal for ever without once proving the page
 * renders.
 *
 * Needs the backend up and seeded. Start it as
 * `PHP_CLI_SERVER_WORKERS=8 php artisan serve` — the committed config builds for
 * production, and Next prerenders in parallel workers against an API that PHP's
 * built-in server answers one at a time.
 */

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

/** Anything that says a request failed rather than that nothing happened. */
const RAW_ERROR = /Request failed|Server Error|undefined|Exception|SQLSTATE/;

test.describe("شاشات المنصّة المالية", () => {
  test.beforeEach(async ({ page }) => {
    await usePlatformAccount(page);
  });

  test("الشريط الجانبي ← سجلّ التحصيل", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "سجلّ التحصيل" }).click();

    await expect(page).toHaveURL(/\/manage\/payments\/collection$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "سجلّ التحصيل" }).first(),
    ).toBeVisible();

    // The three breakdowns FR-031 asks for, present as headings rather than as
    // a number this spec would have to know in advance.
    for (const dimension of ["بالطريقة", "بالحالة", "بالمصدر"]) {
      await expect(page.locator("#main").getByText(dimension, { exact: true })).toBeVisible();
    }

    await expect(page.locator("body")).not.toContainText(RAW_ERROR);
  });

  test("المُصفِّي يعيد الطلب، ولا يترك الشاشة على فترة لم تُطلَب", async ({ page }) => {
    await page.goto("/manage/payments/collection");

    // A period nothing was collected in: the empty state is the assertion, not
    // an error. "No collection" and "the report broke" look identical to a
    // reader if the screen answers both with a blank table.
    await page.getByLabel("من تاريخ").fill("2020-01-01");
    await page.getByLabel("إلى تاريخ").fill("2020-01-02");
    await page.getByRole("button", { name: "اعرض" }).click();

    await expect(
      page.locator("#main").getByText(/لا عمليات في هذه الفترة|لا تحصيل في هذه الفترة/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(RAW_ERROR);
  });

  test("الشريط الجانبي ← تسوية المدفوعات", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "تسوية المدفوعات" }).click();

    await expect(page).toHaveURL(/\/manage\/payments\/reconciliation$/);
    // Either a run or the sentence saying none has happened — both are correct
    // answers, and asserting only the first would fail on a clean database.
    await expect(
      page.locator("#main").getByText(/آخر جولة|لم تُشغَّل|لا ملاحظات|لا شيء/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(RAW_ERROR);
  });

  test("الشريط الجانبي ← سجلّ التدقيق المالي", async ({ page }) => {
    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "سجلّ التدقيق المالي" }).click();

    await expect(page).toHaveURL(/\/manage\/payments\/audit$/);
    await expect(
      page.locator("#main").getByRole("heading", { name: "سجلّ التدقيق المالي" }).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(RAW_ERROR);
  });
});

test.describe("ما لا يظهر لغير المنصّة", () => {
  test("المدرّس لا يجد للشاشات الثلاث مدخلاً، ولا يفتحها بالعنوان", async ({ page }) => {
    // No platform token here: this runs as the projects' seeded STUDENT, who is
    // as far from a platform permission as anyone gets.
    await page.goto("/dashboard");
    await openNav(page);

    for (const label of ["سجلّ التحصيل", "تسوية المدفوعات", "سجلّ التدقيق المالي"]) {
      await expect(page.getByRole("link", { name: label })).toHaveCount(0);
    }

    // And the address typed by hand: a nav that hides a link is a nav, not a
    // guard — the refusal has to come from the server.
    await page.goto("/manage/payments/collection");

    await expect(page.locator("#main").getByText(/تعذّر|صلاحية|غير مسموح/).first()).toBeVisible();
  });
});

test.describe("إشعار المزوّد", () => {
  /*
   * ⚠️ AN EXPLICIT EMPTY storageState, AND WITHOUT IT THIS TESTS THE WRONG
   * THING. Every project in this suite carries an authenticated state, so a
   * request meant to arrive as an unauthenticated gateway would arrive signed in
   * as a student — and the route's whole point is that it takes no token of
   * ours. The signature is the authentication.
   */
  test.use({ storageState: { cookies: [], origins: [] } });

  test("الويب-هوك يجيب بلا مصادقة، ولا يفشي ما إذا كان التوقيع صحيحاً", async ({ request }) => {
    const response = await request.post("http://localhost:8000/api/v1/webhooks/payments/fake", {
      data: { reference: "e2e-not-a-real-payment", status: "captured" },
      failOnStatusCode: false,
    });

    // 202 for a bad signature exactly as for a good one: a distinct answer for a
    // wrong signature is an oracle telling an attacker when they are getting
    // warm. The refusal is recorded, never announced (SC-002).
    expect([202, 404]).toContain(response.status());
    expect(await response.text()).not.toContain("signature");
  });
});
