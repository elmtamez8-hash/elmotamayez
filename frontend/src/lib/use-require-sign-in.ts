"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";

import { useAuth } from "@/lib/auth-context";

/**
 * Sends a signed-out visitor to the sign-in page, carrying the address they were on.
 *
 * Extracted from the shell (spec 039) so a full-screen page OUTSIDE it — the
 * whiteboard, which is the tab a teacher shares — guards itself the same way.
 * The caller still renders its own loading state and nothing while `!user`.
 *
 * ⚠️ THE ADDRESS TRAVELS WITH THEM (027 · FR-005). This used to push a bare
 * `/login`, which was harmless while every screen behind the shell was one a
 * signed-in person had navigated to from inside — and became a silent loss the
 * moment `/subscribe` arrived. A visitor who presses «اشترك في هذه المجموعة»
 * on a public course page lands here, is bounced, signs in, and is deposited
 * on their dashboard with the group they chose forgotten.
 *
 * ⚠️ AND THE QUERY IS PART OF IT. `/subscribe` carries its whole state in the
 * address (`?course=…&cohort=…`), so a redirect that kept only the pathname
 * would return them to a screen with nothing chosen.
 *
 * ⚠️ READ FROM `window.location`, NOT `useSearchParams()`. That hook forces a
 * Suspense boundary at prerender time, and a build-time route error in this
 * tree is a 500 on the WHOLE application rather than on one page. This effect
 * runs only in the browser, after mount, where the location is simply there.
 */
export function useRequireSignIn() {
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) {
      const intended = `${pathname}${window.location.search}`;

      router.push(`/login?next=${encodeURIComponent(intended)}`);
    }
  }, [user, loading, router, pathname]);

  return { user, loading };
}
