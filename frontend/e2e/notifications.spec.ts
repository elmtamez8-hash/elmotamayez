import { test, expect, type Page } from "@playwright/test";

import { useIsolatedAccount } from "./isolated-accounts";

/**
 * The three screens spec 003 added, driven the way a person drives them.
 *
 * These assert on behaviour the API tests cannot see: that the settings screen
 * is reachable at all, that WhatsApp is absent rather than disabled, and that a
 * mandatory type's checkbox cannot be clicked off. Those are the three places
 * the backend rules become a promise to the user.
 *
 * The suite runs signed in as the seeded student (see auth.setup.ts), and writes
 * through the real API — so it needs the backend up and seeded, same as the rest
 * of the e2e suite.
 */

/** The bell reports its unread count in its accessible name, so read it there. */
async function unreadFromBell(page: Page): Promise<number> {
  const bell = page.getByRole("link", { name: /الإشعارات/ }).first();
  const label = (await bell.getAttribute("aria-label")) ?? "";
  const digits = label.match(/(\d+)/);

  return digits ? Number(digits[1]) : 0;
}

test.describe("مركز الإشعارات", () => {
  test("الجرس يظهر في الترويسة ويقود إلى المركز", async ({ page }) => {
    await page.goto("/dashboard");

    const bell = page.getByRole("link", { name: /الإشعارات/ }).first();
    await expect(bell).toBeVisible();

    await bell.click();
    await expect(page).toHaveURL(/\/notifications$/);
  });

  test("المركز يعرض حالته بلا خطأ خام", async ({ page }) => {
    await page.goto("/notifications");

    // Either a list or the empty state — never an English framework message.
    // FR-017 of spec 002: the user must never see a raw error.
    await expect(page.getByText(/لا توجد إشعارات بعد\.|لديك|لا إشعارات غير مقروءة/)).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);
  });

  test("زر تعليم الكل معطَّل حين لا يوجد غير مقروء", async ({ page }) => {
    await page.goto("/notifications");

    const markAll = page.getByRole("button", { name: "تعليم الكل مقروءاً" });

    if ((await unreadFromBell(page)) === 0) {
      await expect(markAll).toBeDisabled();
    } else {
      await expect(markAll).toBeEnabled();
    }
  });
});

test.describe("إعدادات الإشعارات", () => {
  // The bug this catches is the one that actually happened: the screen existed
  // and nothing linked to it.
  test("يمكن الوصول إليها من صفحة الإعدادات", async ({ page }) => {
    await page.goto("/settings");

    await page.getByRole("link", { name: "إعدادات الإشعارات" }).click();

    await expect(page).toHaveURL(/\/settings\/notifications$/);
    await expect(page.getByRole("heading", { name: "إعدادات الإشعارات" })).toBeVisible();
  });

  // FR-030 — an unimplemented channel is absent, not greyed out. A disabled
  // toggle promises a date nobody has committed to.
  test("القنوات غير المُنفَّذة لا تظهر إطلاقاً", async ({ page }) => {
    await page.goto("/settings/notifications");

    const table = page.getByRole("table").first();
    await expect(table.getByRole("columnheader", { name: "داخل المنصة" })).toBeVisible();

    for (const absent of ["واتساب", "تيليجرام", "رسالة نصّية", "إشعار فوري", "البريد الإلكتروني"]) {
      await expect(table.getByRole("columnheader", { name: absent })).toHaveCount(0);
    }
  });

  // FR-029 / SC-013 — "cannot be switched off" has to be true in the UI too, not
  // only in the 422 the API would return.
  test("النوع الإلزامي مؤشَّر ولا يمكن إيقافه", async ({ page }) => {
    await page.goto("/settings/notifications");

    const row = page.getByRole("row").filter({ hasText: "تذكير دفع" });

    await expect(row.getByText("إلزامي")).toBeVisible();

    const box = row.getByRole("checkbox").first();
    await expect(box).toBeChecked();
    await expect(box).toBeDisabled();
  });

  // Writes. A preference is one row per (account, type), so six projects sharing
  // the seeded student are six writers to one value — and the failure that
  // produces reads as a product bug that is not there.
  test("النوع الاختياري يُطفأ ويُحفظ ويبقى مطفأً بعد إعادة التحميل", async ({ page }, testInfo) => {
    await useIsolatedAccount(page, testInfo);

    await page.goto("/settings/notifications");

    const row = page.getByRole("row").filter({ hasText: "نتيجة اختبار" });
    const box = row.getByRole("checkbox").first();

    await expect(box).toBeEnabled();
    await box.uncheck();

    await page.getByRole("button", { name: "احفظ التغييرات" }).click();
    await expect(page.getByText("حُفِظت الإعدادات.")).toBeVisible();

    await page.reload();

    // Assert the screen has finished loading before asserting on what it shows.
    // "checkbox not found" while the page still reads "جارٍ التحميل…" is a slow
    // request reported as a missing feature.
    await expect(page.getByText("جارٍ التحميل…")).toBeHidden();

    await expect(
      page.getByRole("row").filter({ hasText: "نتيجة اختبار" }).getByRole("checkbox").first(),
    ).not.toBeChecked();
  });
});

test.describe("وليّ الأمر والأوصياء", () => {
  // Writes, and the list it asserts against is the account's whole history — six
  // projects adding rows to one account push the row under test off the page.
  test("إضافة وصيّ ثم إلغاء ارتباطه يُبقيه بحالة ملغاة", async ({ page }, testInfo) => {
    await useIsolatedAccount(page, testInfo);

    await page.goto("/family");

    // Still distinct per run: a revoked relation is kept, never deleted, so a
    // fixed name would match the row a previous run left behind.
    const student = `سلمى ${Date.now().toString().slice(-6)}`;

    await page.getByLabel("اسم الطالب").fill(student);
    await page.getByLabel("صفة الارتباط").selectOption("guardian");

    // FR-022: permissions are per-kind. Drop one and the row must show the rest.
    await page.getByLabel("المدفوعات والمستحقّات").uncheck();

    await page.getByRole("button", { name: "إضافة" }).click();

    const row = page.getByRole("listitem").filter({ hasText: student });
    await expect(row).toBeVisible();
    await expect(row.getByText("وصيّ")).toBeVisible();
    await expect(row.getByText("الحضور والغياب")).toBeVisible();
    await expect(row.getByText("المدفوعات والمستحقّات")).toHaveCount(0);

    // FR-023 — revoked, never deleted: the archive of what was already sent has
    // to stay explainable.
    await row.getByRole("button", { name: "إلغاء الارتباط" }).click();

    const revoked = page.getByRole("listitem").filter({ hasText: student });
    await expect(revoked).toBeVisible();

    // Two round trips behind one click — the revoke, then a refetch of the whole
    // list — and no spinner in between, so the row keeps its old text until both
    // land. The suite's raised expect timeout is what covers that.
    await expect(revoked.getByText("ملغاة")).toBeVisible();
    await expect(revoked.getByRole("button", { name: "إلغاء الارتباط" })).toHaveCount(0);
  });

  test("زر الإضافة معطَّل بلا اسم", async ({ page }) => {
    await page.goto("/family");

    await expect(page.getByRole("button", { name: "إضافة" })).toBeDisabled();
  });
});
