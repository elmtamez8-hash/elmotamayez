import { defineConfig, devices } from "@playwright/test";

// SC-013 names three widths and SC-012 names both colour schemes, so the matrix
// below is the success criteria expressed as config — not an arbitrary choice.
const WIDTHS = [
  { name: "mobile", width: 360, height: 780 },
  { name: "tablet", width: 768, height: 1024 },
  { name: "desktop", width: 1440, height: 900 },
];

const SCHEMES = ["light", "dark"] as const;

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? "github" : "list",

  use: {
    baseURL: process.env.BASE_URL ?? "http://localhost:3000",
    locale: "ar",
    timezoneId: "Asia/Qatar",
    trace: "on-first-retry",
  },

  projects: WIDTHS.flatMap(({ name, width, height }) =>
    SCHEMES.map((colorScheme) => ({
      name: `${name}-${colorScheme}`,
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width, height },
        colorScheme,
      },
    })),
  ),

  // A production build, not `next dev`. The dev server compiles each route on
  // first request, which makes a cold run flaky for no product reason, and its
  // unminified bundles are not what SC-007 and SC-016 are about.
  webServer: {
    command: "npm run build && npm run start",
    url: "http://localhost:3000",
    reuseExistingServer: !process.env.CI,
    timeout: 240_000,
  },
});
