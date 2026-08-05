import { test, expect, type Page } from "@playwright/test";

/**
 * The direction, theme and layout criteria that axe cannot see.
 *
 * SC-001 lang/dir · SC-002 no stray Latin · SC-007 no theme flash ·
 * SC-008 no horizontal page scroll at 360px.
 */

const PUBLIC_PAGES = [
  { path: "/", name: "الرئيسية" },
  { path: "/teachers", name: "المدرسون" },
  { path: "/courses", name: "الكورسات" },
  { path: "/pricing", name: "الأسعار" },
];

const PANEL_PAGES = [
  { path: "/dashboard", name: "لوحة التحكم" },
  { path: "/enrollments", name: "تسجيلاتي" },
  { path: "/exams", name: "الاختبارات" },
  { path: "/certificates", name: "الشهادات" },
  { path: "/orders", name: "الطلبات" },
  { path: "/settings", name: "الإعدادات" },
  { path: "/notifications", name: "الإشعارات" },
  { path: "/settings/notifications", name: "إعدادات الإشعارات" },
  { path: "/family", name: "وليّ الأمر والأوصياء" },
  { path: "/members", name: "الأعضاء" },
  { path: "/workspaces", name: "مساحات العمل" },
  { path: "/manage/courses", name: "إدارة الكورسات" },
];

const ALL_PAGES = [...PUBLIC_PAGES, ...PANEL_PAGES];

// The panel redirects unauthenticated visitors, and storageState is applied by
// the project config — so landing on /login means the setup project's token did
// not survive, not that the page is broken. Fail loudly rather than audit the
// wrong page.
async function expectNotRedirectedToLogin(page: Page, path: string) {
  await expect(page, `${path} redirected to /login — storageState missing?`).not.toHaveURL(
    /\/login/,
  );
}

test.describe("SC-001 — الوثيقة عربية واتجاهها من اليمين", () => {
  for (const { path, name } of ALL_PAGES) {
    test(`${name} تُقدَّم بـ lang=ar dir=rtl`, async ({ page }) => {
      await page.goto(path);
      await expectNotRedirectedToLogin(page, path);

      const html = page.locator("html");
      await expect(html).toHaveAttribute("lang", "ar");
      await expect(html).toHaveAttribute("dir", "rtl");
    });
  }
});

test.describe("SC-007 — لا وميض للسمة", () => {
  // Only in a dark project: the flash this guards against is the light default
  // painting before the saved dark preference is read.
  test.skip(
    ({ colorScheme }) => colorScheme !== "dark",
    "الوميض لا يظهر إلا في السمة الليلية",
  );

  for (const { path, name } of [PUBLIC_PAGES[0], PANEL_PAGES[0]]) {
    test(`${name} تبدأ داكنة من أول إطار`, async ({ page }) => {
      await page.addInitScript(() => localStorage.setItem("theme", "dark"));

      // "commit" resolves as soon as the response starts — before <body> has
      // parsed. If data-theme is already set at that point, the script ran
      // synchronously in <head>. A React effect would leave it undefined here,
      // which is exactly the regression that reintroduces the flash.
      await page.goto(path, { waitUntil: "commit" });
      await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");

      await page.waitForLoadState("load");
      const background = await page.evaluate(
        () => getComputedStyle(document.body).backgroundColor,
      );
      const [r, g, b] = background.match(/\d+/g)!.map(Number);
      // Dark surface is #111827 — well under the midpoint on every channel.
      expect(
        Math.max(r, g, b),
        `body background ${background} is not a dark surface`,
      ).toBeLessThan(90);
    });
  }
});

test.describe("SC-008 — لا تمرير أفقي عند ٣٦٠ بكسل", () => {
  test.skip(
    ({ viewport }) => (viewport?.width ?? 0) !== 360,
    "المعيار مضبوط على أضيق قياس",
  );

  for (const { path, name } of ALL_PAGES) {
    test(`${name} لا تتمدّد أفقياً`, async ({ page }) => {
      await page.goto(path);
      await expectNotRedirectedToLogin(page, path);

      // documentElement, not body: a wide table can overflow the root element
      // while body still measures the viewport width.
      const overflow = await page.evaluate(() => ({
        root: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        body: document.body.scrollWidth - document.body.clientWidth,
      }));

      expect(overflow.root, "الجذر يتمدّد أفقياً").toBeLessThanOrEqual(0);
      expect(overflow.body, "جسم الصفحة يتمدّد أفقياً").toBeLessThanOrEqual(0);
    });
  }
});

/**
 * SC-002 — no stray Latin.
 *
 * The allow-list is declared here on purpose: without it the criterion is not
 * checkable, and with an implicit one every change becomes a negotiation over
 * what "counts" as English. Widening it is a visible diff in review.
 */
const ALLOWED_LATIN = [
  "PDF",
  "URL",
  "SMS",
  "OTP",
  "ID",
  "QAR",
  "SAR",
  "AED",
  "EGP",
  "USD",
  "EUR",
  "WhatsApp",
  "FormRequest",
];

// Emails, links, file names and anything a teacher typed. The product shows
// user content verbatim and does not translate it — an explicit assumption in
// the spec, not an oversight.
const USER_CONTENT = /[\w.+-]+@[\w.-]+|https?:\/\/\S+|\/\S+|\.\w{2,4}\b/g;

test.describe("SC-002 — لا نصّ إنجليزي ظاهر", () => {
  test.skip(
    ({ viewport, colorScheme }) =>
      (viewport?.width ?? 0) !== 1440 || colorScheme !== "light",
    "النصّ لا يتغيّر بالقياس ولا بالسمة — يكفي مشروع واحد",
  );

  for (const { path, name } of PANEL_PAGES) {
    test(`${name} خالية من النصّ اللاتيني`, async ({ page }) => {
      await page.goto(path);
      await expectNotRedirectedToLogin(page, path);

      const visible = await page.evaluate(() => document.body.innerText);

      const leftovers = visible
        .replace(USER_CONTENT, " ")
        .split(/\s+/)
        .filter((word) => /^[A-Za-z][A-Za-z'-]{2,}$/.test(word))
        .filter((word) => !ALLOWED_LATIN.includes(word));

      expect(
        [...new Set(leftovers)],
        `نصّ لاتيني غير مُعلَن في ${path}`,
      ).toEqual([]);
    });
  }
});
