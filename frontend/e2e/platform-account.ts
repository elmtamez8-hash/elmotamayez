import { readFileSync } from "node:fs";

import { expect, type Page } from "@playwright/test";

/**
 * The seeded super admin, for the three screens no tenant role can open.
 *
 * The collection report, the payment reconciliation and the financial audit are
 * platform permissions held by nobody inside a workspace — the workspace owner
 * is refused on all three, by design (FR-033). So a spec covering them can use
 * neither the projects' student storageState nor the teacher token: both get a
 * 403, and a spec that asserted "the page is refused" would pass forever without
 * ever proving the page renders.
 *
 * Same shape as `teacher-account.ts` and for the same two reasons: Playwright
 * refuses to let a spec import a setup file, and swapping the projects'
 * storageState would hand the read-only accessibility audits a super admin and
 * quietly change what a dozen passing assertions actually look at.
 */

export const PLATFORM_FILE = "e2e/.auth/platform.json";

/**
 * Point this browser at the seeded platform account.
 *
 * `addInitScript`, not an `evaluate` after `goto`: AuthProvider reads the token
 * on first render, and setting it later leaves that render signed in as the
 * student from storageState — which on these routes is a 403 the spec would
 * then report as a broken page.
 */
export async function usePlatformAccount(page: Page): Promise<void> {
  const { token } = JSON.parse(readFileSync(PLATFORM_FILE, "utf8")) as { token: string };

  expect(token, "no platform token — did the setup project run?").toBeTruthy();

  await page.addInitScript((value) => {
    localStorage.setItem("auth_token", value);
  }, token);
}
