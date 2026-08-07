import { readFileSync } from "node:fs";

import { test, expect, type Page } from "@playwright/test";

import { TEACHER_FILE } from "./auth.setup";

/**
 * The teacher's statement, walked from the sidebar — and audited for what is
 * NOT on it.
 *
 * Entered from the nav rather than by `page.goto`, for the reason sessions.spec
 * gives: a spec that navigates straight to a URL passes just as happily when
 * nothing in the product links there, which is how spec 004's player shipped
 * reachable only by typing its address.
 *
 * The second half is the one this spec exists for. Settlement and billing are
 * two contexts with no shared query, and this asserts that at the last boundary
 * there is — the browser. The backend allowlist already fails its own build on a
 * forbidden field; this catches the case where a page reaches for the billing
 * API instead and renders the same number from a different door.
 *
 * Needs the backend up and seeded: the walk signs in as the seeded teacher,
 * because the default storageState is the seeded STUDENT and a student is
 * refused these routes by design (SC-010).
 */

/**
 * Any name a student's money could arrive under.
 *
 * Machine keys, mirroring `TeacherFieldAllowlist::FORBIDDEN`. Not exhaustive by
 * construction — it cannot be — but every entry here is a field that exists in
 * the billing context today.
 */
const FORBIDDEN_KEYS = [
  "paid_amount",
  "student_paid",
  "sale_price",
  "platform_fee",
  "commission",
  "margin",
  "coupon",
  "discount_minor",
  "student_balance",
  "wallet_balance",
  "credits_remaining",
  "order_uuid",
  "payment_uuid",
  "receipt_url",
];

/**
 * Point this browser at the seeded teacher.
 *
 * The token is minted ONCE by the setup project and read here. Logging in per
 * test would be twelve hits on `throttle:auth` across the six projects, and that
 * limiter allows five a minute for the whole suite — the run would fail on a
 * limit rather than on anything real.
 *
 * `addInitScript`, not an `evaluate` after `goto`: AuthProvider reads the token
 * on first render, and setting it later leaves that render signed in as the
 * student from storageState.
 */
async function signInAsTeacher(page: Page): Promise<void> {
  const { token } = JSON.parse(readFileSync(TEACHER_FILE, "utf8")) as { token: string };

  expect(token, "no teacher token — did the setup project run?").toBeTruthy();

  await page.addInitScript((value) => {
    localStorage.setItem("auth_token", value);
  }, token);
}

async function openNav(page: Page): Promise<void> {
  const width = page.viewportSize()?.width ?? 1440;

  if (width < 768) {
    await page.getByRole("button", { name: "فتح التنقّل" }).click();
  }
}

test.describe("كشف التسوية", () => {
  test("الشريط الجانبي ← كشف التسوية", async ({ page }) => {
    await signInAsTeacher(page);

    await page.goto("/dashboard");
    await openNav(page);

    await page.getByRole("link", { name: "كشف التسوية" }).click();

    await expect(page).toHaveURL(/\/manage\/settlement$/);
    await expect(page.getByRole("heading", { name: "كشف التسوية", level: 2 })).toBeVisible();

    // The window, the totals and the units list — or a stated empty state. Never
    // a raw error: 422 lands under a field and everything else goes through
    // userMessage(), so an English framework string on screen is the bug.
    await expect(page.getByRole("heading", { name: "الفترة الحالية" })).toBeVisible();
    await expect(
      page.getByText(/الصافي المستحق|تعذّر تحميل كشف التسوية/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(
      /Request failed|Server Error|Too Many Attempts|undefined|NaN/,
    );
  });

  test("لا يظهر أي رقم يخصّ دفع الطالب", async ({ page }) => {
    await signInAsTeacher(page);

    // Every settlement payload the page receives, captured as raw text so the
    // check is on what crossed the wire rather than on what React chose to
    // render — a field can be present and merely unrendered, and that is still a
    // field the browser now holds (FR-018).
    const payloads: string[] = [];

    page.on("response", async (response) => {
      if (!response.url().includes("/api/v1/settlement/")) return;

      await response
        .text()
        .then((body) => payloads.push(body))
        // A body already consumed or a download is not a failure of this test.
        .catch(() => undefined);
    });

    await page.goto("/manage/settlement");
    await expect(page.getByRole("heading", { name: "الفترة الحالية" })).toBeVisible();

    expect(payloads.length, "the page fetched no settlement payload at all").toBeGreaterThan(0);

    for (const body of payloads) {
      for (const key of FORBIDDEN_KEYS) {
        // Quoted, so the match is on a JSON KEY. An unquoted search reports
        // "id" inside "individual" and fails a payload that is entirely correct.
        expect(body, `a settlement payload carried "${key}"`).not.toContain(`"${key}"`);
      }
    }

    // And nothing in the visible copy names the other side of the transaction
    // either. A teacher reading "عمولة المنصة" on their own statement is the
    // conversation this context exists to make impossible.
    await expect(page.locator("main")).not.toContainText(
      /عمولة المنصة|سعر البيع|ما دفعه الطالب|رصيد الطالب/,
    );
  });
});
