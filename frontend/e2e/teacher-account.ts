import { readFileSync } from "node:fs";

import { expect, type Page } from "@playwright/test";

/**
 * The seeded teacher, for the specs that are about a teacher's own screens.
 *
 * The six viewport projects carry a STUDENT's storageState, and the settlement
 * routes refuse a student by design (SC-010) — so a spec covering them cannot
 * use it. Swapping the projects' storageState instead would hand the read-only
 * accessibility audits a teacher and quietly change what a dozen passing
 * assertions actually look at; specs opt in one at a time.
 *
 * This module exists because Playwright refuses to let a spec import a setup
 * file — a rule with a real reason: an import would pull the setup's `test`
 * registrations into the spec's own file. So the constant that both sides need
 * lives here, in a plain module that registers nothing, exactly as
 * `isolated-accounts.ts` does for the per-project students.
 */

export const TEACHER_FILE = "e2e/.auth/teacher.json";

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
export async function useTeacherAccount(page: Page): Promise<void> {
  const { token } = JSON.parse(readFileSync(TEACHER_FILE, "utf8")) as { token: string };

  expect(token, "no teacher token — did the setup project run?").toBeTruthy();

  await page.addInitScript((value) => {
    localStorage.setItem("auth_token", value);
  }, token);
}
