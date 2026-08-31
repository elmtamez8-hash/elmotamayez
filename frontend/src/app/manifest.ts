import type { MetadataRoute } from "next";

import { PLATFORM_NAME } from "@/lib/platform";

/**
 * The installed application (spec 012 · US2 · FR-011).
 *
 * ⚠️ `dir` AND `lang` ARE DECLARED, AND A MANIFEST SILENT ABOUT THEM OPENS THE
 * INSTALLED APP LAID OUT LEFT TO RIGHT. The website is right — `layout.tsx`
 * carries `lang="ar" dir="rtl"` — but the launcher, the splash screen and the
 * title bar are drawn by the OS from THIS file, before a single line of ours
 * runs. Two answers to one question, and the one nobody wrote is the one the
 * user sees first.
 *
 * ⚠️ AND THIS IS `app/manifest.ts`, NOT `public/manifest.json`. Next injects the
 * `<link rel="manifest">` itself for the file convention, so a hand-written tag
 * plus a static file is a second copy that goes stale the day the brand name
 * moves — and `PLATFORM_NAME` here is the same constant the header spells.
 *
 * `start_url` is `/dashboard` rather than `/`: somebody who installed the app
 * has an account. `/` is the marketplace landing page, which is a sales pitch to
 * a person who has already bought.
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: PLATFORM_NAME,
    short_name: PLATFORM_NAME,
    description: "منصّة تعليمية: دروسك وحصصك واختباراتك في مكان واحد.",
    lang: "ar",
    dir: "rtl",
    start_url: "/dashboard",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    // The page's own ground and brand, so the splash screen does not flash a
    // colour the product never uses. Taken from `@theme` in globals.css:
    // --color-surface and --color-primary.
    background_color: "#faf6f0",
    theme_color: "#8a1538",
    icons: [
      {
        src: "/brand/icon-192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/brand/icon-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "any",
      },
      /*
        `maskable` is declared only because the mark is actually built to survive
        it: it is held inside 60% of a solid maroon square, so an OS cropping to
        a circle cuts background and nothing else. Declared over an edge-to-edge
        logo, this promise is what gets the letters shaved off on Android.
      */
      {
        src: "/brand/icon-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
  };
}
