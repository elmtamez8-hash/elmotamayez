"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { hasAuthToken } from "@/lib/api";

/**
 * Sends the installed app to the right first screen: the dashboard for somebody
 * with a session, the public home for a visitor. `replace`, never `push` — the
 * back button must not return to a page whose only job was to leave.
 */
export function StartRedirect() {
  const router = useRouter();

  useEffect(() => {
    router.replace(hasAuthToken() ? "/dashboard" : "/");
  }, [router]);

  return (
    <p role="status" className="p-8 text-center text-sm text-ink-muted">
      جارٍ الفتح…
    </p>
  );
}
