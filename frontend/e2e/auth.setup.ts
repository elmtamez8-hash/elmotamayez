import { mkdirSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";

import { test as setup, expect, type APIRequestContext } from "@playwright/test";

import { ISOLATED_FILE, PROJECT_NAMES } from "./isolated-accounts";

/**
 * Produces the authenticated storageState the six viewport projects depend on.
 *
 * SC-006 requires the axe audit to cover the panel, and every panel route
 * redirects to /login without a token. The alternative — signing in inside each
 * test — is 18 pages × 6 projects = 108 logins per run against an endpoint
 * throttled at 5/minute.
 */

const AUTH_FILE = "e2e/.auth/user.json";

/**
 * The seeded teacher's token, minted ONCE for the whole run.
 *
 * The settlement screens are the teacher's, and a student is refused them by
 * design — so a spec that covers them cannot use the storageState above. Signing
 * in inside each test would be 2 specs × 6 projects = 12 hits on `throttle:auth`,
 * which allows five a minute and keys guests on the IP with no route in the hash:
 * the whole suite shares one counter. One login here, read from a file by every
 * project, is the same trick the isolated accounts use and for the same reason.
 */
export const TEACHER_FILE = "e2e/.auth/teacher.json";

// Seeded by `php artisan migrate:fresh --seed`. A student, not the teacher: the
// student sees the larger set of read-only screens, and the teacher-only pages
// are covered by their own audit list.
const EMAIL = process.env.E2E_EMAIL ?? "student@example.com";
const PASSWORD = process.env.E2E_PASSWORD ?? "password";

const TEACHER_EMAIL = process.env.E2E_TEACHER_EMAIL ?? "teacher@example.com";

// The login route is throttled at 5/minute. That is correct for production and
// hostile to a test suite: one aborted run leaves the window spent, and every
// retry for the next minute fails on a limit rather than on anything real.
setup.setTimeout(180_000);

/**
 * Sign in, waiting out the rate limit rather than fighting it.
 *
 * Shared by both accounts below: the limiter keys guests on the IP with no route
 * in the hash, so every login in the run draws on one counter and a second
 * hand-written retry loop would be a second thing to get wrong.
 */
async function login(request: APIRequestContext, email: string): Promise<string> {
  const credentials = { email, password: PASSWORD };

  let response = await request.post("/api/v1/auth/login", { data: credentials });

  for (let attempt = 0; response.status() === 429 && attempt < 3; attempt++) {
    // Wait out the window rather than hammering it — retrying immediately only
    // extends it.
    await new Promise((resolve) => setTimeout(resolve, 62_000));
    response = await request.post("/api/v1/auth/login", { data: credentials });
  }

  // A clear failure here beats 108 downstream redirects to /login: the usual
  // cause is a backend that is not running or a database that was not seeded.
  expect(
    response.ok(),
    `login failed for ${email} (${response.status()}). Is the backend up on :8000 and seeded?`,
  ).toBeTruthy();

  const { token } = (await response.json()) as { token: string };
  expect(token, `login succeeded for ${email} but returned no token`).toBeTruthy();

  return token;
}

setup("authenticate", async ({ page, request }) => {
  const token = await login(request, EMAIL);

  // localStorage is per-origin, so it only exists once a page on that origin has
  // loaded. The public home page is the cheapest one to load.
  await page.goto("/");
  await page.evaluate((value) => localStorage.setItem("auth_token", value), token);

  await page.context().storageState({ path: AUTH_FILE });
});

setup("mint the teacher token", async ({ request }) => {
  const token = await login(request, TEACHER_EMAIL);

  // Written as a token rather than as a second storageState, deliberately:
  // swapping the projects' storageState would hand the read-only audits a
  // teacher instead of a student and quietly change what a dozen passing
  // accessibility assertions actually look at. Specs that need the teacher opt
  // in one at a time.
  mkdirSync(dirname(TEACHER_FILE), { recursive: true });

  writeFileSync(TEACHER_FILE, JSON.stringify({ token }, null, 2));
});

/**
 * One throwaway student per project, for the specs that write.
 *
 * The read-only audits share the seeded student and are right to: they change
 * nothing, and six projects reading the same rows is exactly what a real Tuesday
 * looks like. Tests that *mutate* cannot share it. A notification preference is
 * one row per (account, type), so six projects toggling it are six writers to one
 * value — one unchecks and asserts, another has already re-checked, and the
 * failure names a product bug that does not exist.
 *
 * Registered here rather than per worker on purpose: `throttle:registration`
 * allows ten a minute per IP, and a worker-scoped account would scale with the
 * machine's core count until a fast laptop started failing on the limit. Six is
 * the number of projects and does not grow.
 */
setup("mint isolated accounts", async ({ request }) => {
  const accounts: Record<string, string> = {};

  for (const project of PROJECT_NAMES) {
    accounts[project] = await registerStudent(request, project);
  }

  // The sibling setup test creates this directory as a side effect of writing
  // storageState, and with fullyParallel the two can be in different workers —
  // so do not depend on it having happened.
  mkdirSync(dirname(ISOLATED_FILE), { recursive: true });

  writeFileSync(ISOLATED_FILE, JSON.stringify(accounts, null, 2));
});

async function registerStudent(request: APIRequestContext, project: string): Promise<string> {
  // Unique per project AND per run: the account is never cleaned up, and a fixed
  // address would collide with the previous run's row on `users.email`.
  const stamp = `${project}-${Date.now().toString(36)}`;

  const response = await request.post("/api/v1/auth/register/student", {
    headers: { "Idempotency-Key": `e2e-${stamp}` },
    data: {
      first_name: "اختبار",
      last_name: project,
      email: `e2e-${stamp}@example.test`,
      password: "e2e-password",
      password_confirmation: "e2e-password",
      phone: `+9745${Date.now().toString().slice(-7)}`,
      country: "QA",
      grade_level_slug: "secondary",
      terms_accepted: true,
    },
  });

  expect(
    response.ok(),
    `could not mint an isolated account for ${project} (${response.status()}): ${await response.text()}`,
  ).toBeTruthy();

  const { token } = (await response.json()) as { token: string };

  return token;
}
