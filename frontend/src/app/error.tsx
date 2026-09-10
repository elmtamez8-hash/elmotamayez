"use client";

import { useEffect } from "react";

import Link from "next/link";

/**
 * The screen for a client-side exception, which the product did not have.
 *
 * ⚠️ WITHOUT THIS FILE NEXT SHOWS ITS OWN, AND WHAT IT SHOWS IS ONE ENGLISH
 * SENTENCE: «Application error: a client-side exception has occurred (see the
 * browser console for more information)». Left-aligned, in a system font, on a
 * white ground, on an Arabic-only RTL product — the same objection
 * `not-found.tsx` was written for, reached from the other side. And it is
 * WORSE than the 404 it mirrors, because it offers no way anywhere: no retry,
 * no link, no heading, nothing to press. A reader whose lesson page threw is
 * left on a dead end telling them, in a language many of them do not read, to
 * open developer tools.
 *
 * ⚠️ AND IT DEFEATS THE PRODUCT'S OWN RULE FROM UNDERNEATH. «Never show a raw
 * error to the user» is kept everywhere a request is made — 422 through
 * `fieldErrors()`, everything else through `userMessage()`. A RENDER-time throw
 * passes none of that: no fetch is involved, so no handler of ours is ever
 * reached. This boundary is where that rule gets kept for the one case the
 * others cannot see.
 *
 * ⚠️ THE `digest` IS THE POINT, NOT DECORATION. A production build strips the
 * message and the stack and hands the boundary a hash — and the same hash is
 * written into the server's log for that render. So it is the ONE string that
 * connects «a person says the app broke» to «this is what threw», and printing
 * it turns an unreproducible report into a lookup. It was measured the hard way
 * on 2026-09-10: a crash was reported with no URL and no console line, twelve
 * pages were walked by hand, and nothing was found — because there was nothing
 * on the screen to quote.
 *
 * ⚠️ TWO BUTTONS, AND THEY ARE NOT THE SAME BUTTON. `reset()` re-renders this
 * segment with the client it already has, which fixes a throw on transient data
 * and CANNOT fix a stale bundle: a tab left open across a deploy asks for a
 * chunk the new build replaced, and only a fresh document load gets one. So the
 * reload is offered beside the retry rather than instead of it — retrying for
 * ever against a chunk that no longer exists is the loop this pairing avoids.
 *
 * ⚠️ AND THERE IS DELIBERATELY NO `global-error.tsx`. That file must render its
 * own `<html>` and `<body>`, and this repository has exactly one root layout on
 * purpose — «adding a second `<html>` anywhere re-splits the product». It would
 * only ever fire for a throw inside the root layout itself, which is a server
 * component doing two reads; the cost of a second document shell is not worth
 * covering it. Everything below the root layout — every page, every nested
 * layout, every client component — lands here.
 */

/** Where somebody whose screen just broke most plausibly wants to be. */
const WAYS_OUT: { href: string; label: string }[] = [
  { href: "/dashboard", label: "لوحة التحكم" },
  { href: "/enrollments", label: "كورساتي" },
  { href: "/schedule", label: "جدولي" },
];

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    /*
      ⚠️ THE CONSOLE LINE IS FOR THE PERSON HELPING, NOT FOR US — nothing here
      ships errors anywhere, and this file is not the place to start doing it.
      In development it carries the real message and stack; in production it
      carries the digest, which is what the screen already shows. Either way the
      first thing anybody is asked for now exists without them reproducing it.
    */
    console.error("[error boundary]", error);
  }, [error]);

  return (
    <main className="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-6 py-16 text-center">
      <h1 className="mb-3 text-2xl font-bold text-ink">تعذّر عرض هذه الصفحة</h1>

      <p className="mb-8 max-w-md text-sm leading-relaxed text-ink-muted">
        حدث خطأ أثناء عرض الصفحة. لم يضِع شيء من بياناتك — كلّ ما يخصّك ما زال في
        مكانه. جرّب مرّة أخرى، وإن تكرّر فأعد تحميل الصفحة كاملة.
      </p>

      <div className="mb-8 flex flex-col gap-3 sm:flex-row">
        <button
          type="button"
          onClick={reset}
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          إعادة المحاولة
        </button>

        <button
          type="button"
          onClick={() => window.location.reload()}
          className="rounded-xl border border-line bg-surface-raised px-5 py-2.5 text-sm font-semibold text-ink transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          إعادة تحميل الصفحة
        </button>
      </div>

      {error.digest && (
        <p className="mb-8 text-xs leading-relaxed text-ink-muted">
          إن راسلت الدعم، أرفق هذا الرمز:{" "}
          <code className="rounded-lg bg-primary-soft px-2 py-1 font-mono text-ink">
            {error.digest}
          </code>
        </p>
      )}

      <nav aria-label="روابط بديلة" className="flex flex-wrap justify-center gap-3">
        {WAYS_OUT.map((way) => (
          <Link
            key={way.href}
            href={way.href}
            className="rounded-full px-4 py-2 text-sm font-semibold text-primary-ink underline transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {way.label}
          </Link>
        ))}
      </nav>
    </main>
  );
}
