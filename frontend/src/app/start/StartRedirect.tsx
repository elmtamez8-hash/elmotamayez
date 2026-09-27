"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { homePathFor, useAuth } from "@/lib/auth-context";

/**
 * Sends the installed app to the right first screen: for somebody with a
 * session, EXACTLY where signing in would have sent them; for a visitor, the
 * public home. `replace`, never `push` — the back button must not return to a
 * page whose only job was to leave.
 *
 * ⛔ THE DESTINATION IS `homePathFor()`, THE LOGIN PAGE'S OWN RULE (owner
 * decision 2026-09-27), never a second copy of it: a student lands on the
 * marketplace and a teacher on `/dashboard` because that is what `/login` does
 * for each, and the day that rule moves this page moves with it.
 *
 * ⚠️ IT WAITS FOR `loading`. The provider exchanges the stored token for a
 * profile first; deciding before that answer would read every signed-in person
 * as a visitor. A token the server refuses is cleared one layer down, so that
 * reader correctly becomes a visitor.
 */
export function StartRedirect() {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;

    router.replace(user === null ? "/" : homePathFor(user));
  }, [loading, user, router]);

  return (
    <p role="status" className="p-8 text-center text-sm text-ink-muted">
      جارٍ الفتح…
    </p>
  );
}
