import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

/**
 * SC-012 and SC-013: every public page, both colour schemes, three widths, zero
 * axe violations. The matrix lives in playwright.config.ts, so this file only
 * lists the pages — each one runs six times.
 *
 * Scoped to WCAG 2 A/AA. Best-practice rules are useful advice but they are not
 * the bar the criteria set, and failing a build on advice trains people to
 * ignore the report.
 */
const PAGES = [
  { path: "/", name: "الرئيسية" },
  { path: "/teachers", name: "المدرسون" },
  { path: "/courses", name: "الكورسات" },
  { path: "/signup/student", name: "تسجيل طالب" },
  { path: "/signup/teacher", name: "تسجيل مدرّس" },
  { path: "/signup/parent", name: "تسجيل وليّ أمر" },

  // The panel, added in 002. It is behind a guard, so these only resolve because
  // the setup project wrote an authenticated storageState (SC-006).
  { path: "/login", name: "تسجيل الدخول" },
  { path: "/register", name: "إنشاء حساب" },
  { path: "/dashboard", name: "لوحة التحكم" },
  { path: "/enrollments", name: "تعلّمي" },
  { path: "/exams", name: "الاختبارات" },
  { path: "/exams/new", name: "اختبار جديد" },
  { path: "/certificates", name: "الشهادات" },
  { path: "/orders", name: "الطلبات" },
  { path: "/settings", name: "الإعدادات" },
  { path: "/members", name: "الأعضاء" },
  { path: "/workspaces", name: "مساحات العمل" },
  { path: "/workspaces/new", name: "مساحة عمل جديدة" },
  { path: "/manage/courses", name: "إدارة الكورسات" },
  { path: "/manage/courses/new", name: "كورس جديد" },
];

function audit(page: import("@playwright/test").Page) {
  return new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();
}

for (const { path, name } of PAGES) {
  test(`${name} has no accessibility violations`, async ({ page }) => {
    await page.goto(path);

    const results = await audit(page);

    // Printed rather than just counted: "expected 0, got 3" sends the reader to
    // the trace, and the rule id and selector are what they actually need.
    expect(
      results.violations.map((v) => `${v.id} — ${v.nodes.map((n) => n.target).join(", ")}`),
    ).toEqual([]);
  });
}

test("a teacher profile has no accessibility violations", async ({ page }) => {
  await page.goto("/teachers");

  const href = await page.locator('a[href^="/teachers/"]').first().getAttribute("href");
  test.skip(href === null, "no published teacher in the seeded data");

  await page.goto(`${href}`);
  const results = await audit(page);

  expect(
    results.violations.map((v) => `${v.id} — ${v.nodes.map((n) => n.target).join(", ")}`),
  ).toEqual([]);
});

// The reviews tab is the one panel whose content is a list of user-submitted
// text, and the star distribution is the one place a chart could have been drawn
// with colour alone.
test("the reviews tab has no accessibility violations", async ({ page }) => {
  await page.goto("/teachers");

  const href = await page.locator('a[href^="/teachers/"]').first().getAttribute("href");
  test.skip(href === null, "no published teacher in the seeded data");

  await page.goto(`${href}?tab=reviews`);
  const results = await audit(page);

  expect(
    results.violations.map((v) => `${v.id} — ${v.nodes.map((n) => n.target).join(", ")}`),
  ).toEqual([]);
});

test("the language and direction are declared on the document", async ({ page }) => {
  await page.goto("/");

  // Not an axe rule at this level, but the single attribute that decides whether
  // a screen reader reads Arabic as Arabic.
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
});
