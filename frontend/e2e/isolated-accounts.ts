import { readFileSync } from "node:fs";

import { expect, type Page, type TestInfo } from "@playwright/test";

/**
 * A private account per project, for the specs that write.
 *
 * The suite runs six projects in parallel against one backend. That is harmless
 * while every test only reads — and a race the moment one writes, because the
 * seeded student is a single row set that all six share. Both failures this
 * replaces were of that shape: a preference toggled off and asserted while
 * another project had already toggled it back, and a guardian list that six
 * writers grew past the row under test.
 *
 * Minted by `auth.setup.ts`; read here. Kept out of `storageState` deliberately:
 * swapping the projects' storageState would hand the read-only audits a brand-new
 * student with no enrolments and no history, quietly changing what a dozen
 * passing accessibility assertions actually look at.
 */

export const ISOLATED_FILE = "e2e/.auth/isolated.json";

/** Must match the project names built in playwright.config.ts. */
export const PROJECT_NAMES = [
  "mobile-light",
  "mobile-dark",
  "tablet-light",
  "tablet-dark",
  "desktop-light",
  "desktop-dark",
] as const;

/**
 * Point this browser at its project's own account.
 *
 * Call before the first navigation of a mutating test. `addInitScript` runs
 * before any page script, so the token is in place before `AuthProvider` reads
 * it — setting it after a `goto` would leave the first render signed in as
 * somebody else.
 */
export async function useIsolatedAccount(page: Page, testInfo: TestInfo): Promise<void> {
  const accounts = JSON.parse(readFileSync(ISOLATED_FILE, "utf8")) as Record<string, string>;
  const token = accounts[testInfo.project.name];

  expect(
    token,
    `no isolated account for ${testInfo.project.name} — did the setup project run?`,
  ).toBeTruthy();

  await page.addInitScript((value) => {
    localStorage.setItem("auth_token", value);
  }, token);
}
