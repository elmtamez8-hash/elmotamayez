import Link from "next/link";

import { PLATFORM_NAME } from "@/lib/platform";

/**
 * The page for an address that leads nowhere.
 *
 * ⚠️ IT REPLACES NEXT'S OWN, WHICH IS A BLACK-ON-WHITE ENGLISH STRIP. On an
 * Arabic-only, RTL, warm-limestone product, «This page could not be found»
 * arrives left-aligned in a system font on a white ground — it does not read as
 * this site at all, which is exactly when a reader concludes something is
 * broken rather than mistyped. It also carried an inline `<style>` block that a
 * dark-mode browser extension rewrote before React hydrated, producing the
 * hydration-mismatch error in the console; our own page has no such block, so
 * that noise goes with it.
 *
 * ⚠️ AND IT IS A SERVER COMPONENT WITH NO STATE. `not-found.tsx` is rendered for
 * an unmatched URL and, in the App Router, is NOT wrapped by the `(app)` or
 * `(public)` layouts — only by the root one. So it cannot assume a signed-in
 * reader, a sidebar, or the API: an unknown address is exactly the case where
 * you know least about who is looking. Everything here is a plain link.
 *
 * ⚠️ THE WAYS OUT ARE THE POINT. A dead end with nothing on it is worse than the
 * mistake that produced it — the same rule `EmptyState` and `ErrorState` follow,
 * and the reason both of them name a next action rather than stating an absence.
 */

export const metadata = {
  title: `الصفحة غير موجودة — ${PLATFORM_NAME}`,
};

/** Where somebody who lands here most plausibly wanted to be. */
const WAYS_OUT: { href: string; label: string; hint: string }[] = [
  { href: "/dashboard", label: "لوحة التحكم", hint: "نقطة البداية لكل شيء" },
  { href: "/enrollments", label: "كورساتي", hint: "ما تدرسه الآن" },
  { href: "/schedule", label: "جدولي", hint: "حصصك القادمة" },
  { href: "/notifications", label: "الإشعارات", hint: "ما وصلك من مدرّسيك" },
];

export default function NotFound() {
  return (
    <main className="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-6 py-16 text-center">
      {/*
        ⚠️ THE NUMBER IS DECORATION AND IS HIDDEN FROM A SCREEN READER. «٤٠٤»
        read aloud before the sentence is three digits of nothing; the heading
        under it is what carries the meaning, which is the rule every state in
        this product follows.
      */}
      <p aria-hidden="true" className="mb-2 text-6xl font-bold text-primary-soft">
        ٤٠٤
      </p>

      <h1 className="mb-3 text-2xl font-bold text-ink">لا توجد صفحة على هذا العنوان</h1>

      <p className="mb-8 max-w-md text-sm leading-relaxed text-ink-muted">
        ربّما تغيّر الرابط، أو كان في رسالة قديمة، أو فيه خطأ مطبعيّ. لم تفقد شيئاً —
        كلّ ما يخصّك ما زال في مكانه.
      </p>

      <nav aria-label="روابط بديلة" className="mb-8 grid w-full grid-cols-1 gap-3 sm:grid-cols-2">
        {WAYS_OUT.map((way) => (
          <Link
            key={way.href}
            href={way.href}
            className="rounded-2xl border border-line bg-surface-raised p-4 text-start transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <span className="block font-semibold text-ink">{way.label}</span>
            <span className="mt-0.5 block text-xs text-ink-muted">{way.hint}</span>
          </Link>
        ))}
      </nav>

      {/*
        ⚠️ NO «رجوع» BUTTON. It needs `history.back()`, which needs a client
        component and a browser — and on a link opened from a notification or a
        message there is no previous page in this tab to go back to, so the
        control would do nothing on exactly the path that leads here most often.
      */}
      <Link
        href="/"
        className="rounded-full px-4 py-2 text-sm font-semibold text-primary-ink underline transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        العودة إلى الصفحة الرئيسيّة
      </Link>
    </main>
  );
}
