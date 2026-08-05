import { defineConfig, devices } from "@playwright/test";

/**
 * A run against an already-running dev server, for use during development.
 *
 * The committed config builds and serves its own production server on :3000,
 * which is the right default — the dev server compiles each route on first
 * request, and its unminified bundles are not what the performance and
 * theme-flash criteria are about. Use that one for anything conclusive:
 *
 *   npx playwright test
 *
 * This override exists for the case where :3000 is already taken and you want a
 * quick pass against a dev server on another port:
 *
 *   BASE_URL=http://localhost:3002 npx playwright test --config e2e/playwright.local.config.ts
 *
 * It drops `webServer` entirely so nothing is built or started, which also keeps
 * a build from fighting a running `npm run dev` over `.next/`.
 */

const WIDTHS = [
  { name: "mobile", width: 360, height: 780 },
  { name: "tablet", width: 768, height: 1024 },
  { name: "desktop", width: 1440, height: 900 },
];

const SCHEMES = ["light", "dark"] as const;

export default defineConfig({
  testDir: ".",
  fullyParallel: true,
  reporter: "list",

  use: {
    baseURL: process.env.BASE_URL ?? "http://localhost:3002",
    locale: "ar",
    timezoneId: "Asia/Qatar",
    trace: "on-first-retry",
  },

  projects: [
    { name: "setup", testMatch: /auth\.setup\.ts/ },

    ...WIDTHS.flatMap(({ name, width, height }) =>
      SCHEMES.map((colorScheme) => ({
        name: `${name}-${colorScheme}`,
        dependencies: ["setup"],
        use: {
          ...devices["Desktop Chrome"],
          viewport: { width, height },
          colorScheme,
          storageState: "e2e/.auth/user.json",
        },
      })),
    ),
  ],
});
