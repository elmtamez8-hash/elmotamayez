"use client";

import type { ReactNode } from "react";

import { useRequireSignIn } from "@/lib/use-require-sign-in";

/**
 * The whiteboard lives OUTSIDE the shell: it is a full tab with no sidebar,
 * because it is the thing the teacher shares with the class (spec 039). So it
 * guards sign-in itself — the shell's guard does not reach here — and renders
 * nothing until a signed-in teacher is known, or the canvas would call the API
 * without a token.
 *
 * No `metadata` export: this is a client file. The page sets its own title.
 */
export default function WhiteboardLayout({ children }: { children: ReactNode }) {
  const { user, loading } = useRequireSignIn();

  if (loading) {
    return (
      <div className="flex min-h-dvh items-center justify-center">
        <p className="text-ink-muted">جارٍ التحميل…</p>
      </div>
    );
  }

  if (!user) return null;

  return <>{children}</>;
}
