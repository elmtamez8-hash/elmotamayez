import { test as setup, expect } from "@playwright/test";

/**
 * Produces the authenticated storageState the six viewport projects depend on.
 *
 * SC-006 requires the axe audit to cover the panel, and every panel route
 * redirects to /login without a token. The alternative — signing in inside each
 * test — is 18 pages × 6 projects = 108 logins per run against an endpoint
 * throttled at 5/minute.
 */

const AUTH_FILE = "e2e/.auth/user.json";

// Seeded by `php artisan migrate:fresh --seed`. A student, not the teacher: the
// student sees the larger set of read-only screens, and the teacher-only pages
// are covered by their own audit list.
const EMAIL = process.env.E2E_EMAIL ?? "student@example.com";
const PASSWORD = process.env.E2E_PASSWORD ?? "password";

// The login route is throttled at 5/minute. That is correct for production and
// hostile to a test suite: one aborted run leaves the window spent, and every
// retry for the next minute fails on a limit rather than on anything real.
setup.setTimeout(180_000);

setup("authenticate", async ({ page, request }) => {
  let response = await request.post("/api/v1/auth/login", {
    data: { email: EMAIL, password: PASSWORD },
  });

  for (let attempt = 0; response.status() === 429 && attempt < 3; attempt++) {
    // Wait out the window rather than hammering it — retrying immediately only
    // extends it.
    await new Promise((resolve) => setTimeout(resolve, 62_000));
    response = await request.post("/api/v1/auth/login", {
      data: { email: EMAIL, password: PASSWORD },
    });
  }

  // A clear failure here beats 108 downstream redirects to /login: the usual
  // cause is a backend that is not running or a database that was not seeded.
  expect(
    response.ok(),
    `login failed (${response.status()}). Is the backend up on :8000 and seeded?`,
  ).toBeTruthy();

  const { token } = (await response.json()) as { token: string };
  expect(token, "login succeeded but returned no token").toBeTruthy();

  // localStorage is per-origin, so it only exists once a page on that origin has
  // loaded. The public home page is the cheapest one to load.
  await page.goto("/");
  await page.evaluate((value) => localStorage.setItem("auth_token", value), token);

  await page.context().storageState({ path: AUTH_FILE });
});
