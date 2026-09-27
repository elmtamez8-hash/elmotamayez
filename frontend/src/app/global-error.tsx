"use client";

import { useEffect, useState } from "react";

import "./globals.css";
import { reloadOnceForStaleChunk } from "@/lib/stale-chunk";

/**
 * The last boundary: a throw inside the ROOT layout itself.
 *
 * ⚠️ IT REPLACES THE ROOT LAYOUT, SO IT DECLARES ITS OWN DOCUMENT — and it must
 * say `lang="ar" dir="rtl"` exactly as `layout.tsx` does, or the one screen a
 * person sees when everything else is gone is the English, left-to-right page
 * spec 002 removed. This is the ONLY second `<html>` in the tree, and it never
 * renders beside the first: Next swaps it in when the root layout cannot render.
 * «Adding a second `<html>` re-splits the product» is about a layout that
 * competes with the root one; this one only ever stands in for it.
 *
 * Everything below the root layout still lands in `error.tsx`, which keeps the
 * shell's fonts, theme and links. This file exists because without it Next
 * shows its own bare English sentence for the root case.
 *
 * ⚠️ NO MESSAGE, NO STACK — only the `digest`, the one string that maps the
 * report to the server log (see `error.tsx`). And no pre-paint theme script:
 * the root layout that runs it is exactly what failed, so the page takes the
 * light palette of `@theme`, which is defined for every token used here.
 */
export default function GlobalError({ error }: { error: Error & { digest?: string } }) {
  const [reloading, setReloading] = useState(false);

  useEffect(() => {
    // A tab that outlived a deploy fails in the root layout's own chunk too.
    if (reloadOnceForStaleChunk(error)) {
      setReloading(true);

      return;
    }

    console.error("[global error boundary]", error);
  }, [error]);

  return (
    <html lang="ar" dir="rtl">
      {/* Not `font-sans`: that token reads `var(--font-cairo)`, which the root
          layout sets and this document does not — an undefined var() voids the
          whole declaration and the page would fall back to the browser's serif. */}
      <body
        className="min-h-screen bg-surface text-ink antialiased"
        style={{ fontFamily: "system-ui, sans-serif" }}
      >
        <main className="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-6 py-16 text-center">
          {reloading ? (
            <p className="text-sm text-ink-muted" role="status">
              جارٍ تحميل أحدث نسخة من الموقع…
            </p>
          ) : (
            <>
              <h1 className="mb-3 text-2xl font-bold text-ink">تعذّر تحميل الموقع</h1>

              <p className="mb-8 max-w-md text-sm leading-relaxed text-ink-muted">
                حدث خطأ لدينا أثناء فتح الصفحة. لم يضِع شيء من بياناتك. أعد تحميل
                الصفحة، وإن تكرّر الخطأ فحاول بعد قليل.
              </p>

              <button
                type="button"
                onClick={() => window.location.reload()}
                className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                إعادة تحميل الصفحة
              </button>

              {error.digest && (
                <p className="mt-8 text-xs leading-relaxed text-ink-muted">
                  إن راسلت الدعم، أرفق هذا الرمز:{" "}
                  <code className="rounded-lg bg-primary-soft px-2 py-1 font-mono text-ink">
                    {error.digest}
                  </code>
                </p>
              )}
            </>
          )}
        </main>
      </body>
    </html>
  );
}
