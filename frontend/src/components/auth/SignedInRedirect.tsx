"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";

import { homePathFor, useAuth } from "@/lib/auth-context";
import { safeNext } from "@/lib/safe-next";

/**
 * «سجّل الدخول» / «أنشئ حساباً» / «نسيت كلمة المرور» mean nothing to somebody who
 * already holds a session — the token is in `localStorage`, shared by every tab
 * — so they are sent on to where they were going: the invitation they were
 * accepting, the `next` they came from, or their home.
 *
 * ⚠️ The guard is for whoever ARRIVES signed in, never for a session created on
 * this page: signing in here sets `user` too, and reading that as "already signed
 * in" would race the form's own navigation to `next` — the defect
 * `(public)/signup/layout.tsx` records in full. `loading` separates the two: the
 * first settled answer is who walked in.
 *
 * The form is not drawn for somebody about to be moved, or it flashes and
 * disappears. Needs a Suspense boundary above it (`useSearchParams`).
 */
export function SignedInRedirect({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [arrivedSignedIn, setArrivedSignedIn] = useState<boolean | null>(null);
  const told = useRef(false);

  useEffect(() => {
    if (loading || arrivedSignedIn !== null) return;

    setArrivedSignedIn(user !== null);
  }, [loading, user, arrivedSignedIn]);

  useEffect(() => {
    if (arrivedSignedIn !== true || user === null || told.current) return;

    told.current = true;

    const invitation = searchParams.get("invitation");

    toast.info("أنت مسجَّل الدخول بالفعل", {
      description: "سجّل الخروج أوّلاً إن أردت الدخول بحسابٍ آخر.",
    });

    router.replace(
      invitation
        ? `/invitations/${encodeURIComponent(invitation)}`
        : safeNext(searchParams.get("next"), homePathFor(user)),
    );
  }, [arrivedSignedIn, user, router, searchParams]);

  if (loading || arrivedSignedIn === true) return null;

  return <>{children}</>;
}
