"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef } from "react";
import { toast } from "sonner";

import { isLearner, panelPathFor, useAuth } from "@/lib/auth-context";

/**
 * Nobody signs up twice.
 *
 * ⚠️ THE LINKS STAY AND THE PAGES DO NOT OPEN — that is the decision, and the two
 * halves are separate. The footer offers all three registration routes on every
 * page, which is right for the visitor they are aimed at; what was wrong is that a
 * signed-in teacher pressing «حساب طالب» was handed a form for a role they cannot
 * hold on an account they already have. Removing the links would hide a real path
 * from the guests who need it; the guard belongs at the destination.
 *
 * ⚠️ AND IT IS A LAYOUT, SO IT COVERS THE THREE FORMS AND ANYTHING ADDED LATER.
 * `student`, `teacher` and `parent` each have their own page, `parent` has a
 * nested step of its own, and a guard copied into each is a guard the fourth one
 * will not have. One file, one rule.
 *
 * ⚠️ `user` IS NULL ON THE SERVER AND ON THE FIRST CLIENT PAINT, so the form
 * renders for everyone and the redirect follows once the token in `localStorage`
 * has been exchanged for a profile. A guest never sees a flicker; a signed-in
 * visitor sees the form for a moment and then their own panel, with the reason
 * said out loud rather than left to be guessed at.
 */
export default function SignupLayout({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const router = useRouter();

  // Once. `useEffect` re-runs on every render the router causes, and a toast
  // per run stacks the same sentence four deep on one screen.
  const told = useRef(false);

  useEffect(() => {
    if (user === null || told.current) return;

    told.current = true;

    toast.info("أنت مسجَّل الدخول بالفعل", {
      description: `لا حاجة لإنشاء حساب جديد — نقلناك إلى ${
        isLearner(user) ? "صفحة دراستك" : "لوحتك"
      }. سجّل الخروج أوّلاً إن أردت حساباً آخر.`,
    });

    // `replace`, not `push`: the form is not a place to come back to with the
    // browser's own back button.
    router.replace(panelPathFor(user));
  }, [user, router]);

  return <>{children}</>;
}
