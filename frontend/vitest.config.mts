import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

/**
 * Unit tests for the screens, which this product did not have.
 *
 * ⚠️ PLAYWRIGHT WAS THE ONLY TEST SURFACE ON THE FRONTEND, AND THAT IS WHY THREE
 * INTERFACE DEFECTS SHIPPED IN ONE DAY: a second tap that silently turned a right
 * answer into a zero on a graded paper, a screen that offered to upload a video for
 * an article, and a player that told every non-Safari student «صيغة غير مدعومة».
 * None of them is reachable by a backend test, and none of them was worth a
 * Playwright run — the committed config builds for production and needs two servers,
 * so nobody runs it inside a development loop. `vitest run` needs neither, which is
 * the whole point: a test somebody actually runs.
 *
 * It does not replace Playwright. That suite answers "does the product work end to
 * end, and is it accessible"; this one answers "does this component do what it says"
 * — and the second question is where today's defects lived.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    setupFiles: "./vitest.setup.ts",
    /*
      ⚠️ SCOPED, BECAUSE THE DEFAULT INCLUDE SWALLOWS THE PLAYWRIGHT SPECS.
      Vitest's default is `**\/*.{test,spec}.*`, which matches `e2e/*.spec.ts` — and
      those import `@playwright/test`, so the first run dies inside somebody else's
      runner with an error that says nothing about the real cause.
    */
    include: ["src/**/*.test.{ts,tsx}"],
  },
  resolve: {
    /*
      The `@/` alias the app is written against.

      `new URL(...)` rather than the bare `"./src"` string: a relative alias is
      resolved against the importing FILE, so a component two directories deep would
      look for `src/` beside itself and fail on an import that works in Next.
    */
    alias: {
      "@/": new URL("./src/", import.meta.url).pathname,
    },
  },
});
