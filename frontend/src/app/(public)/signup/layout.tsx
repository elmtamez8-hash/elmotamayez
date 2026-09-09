"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { api } from "@/lib/api";
import { isLearner, panelPathFor, useAuth } from "@/lib/auth-context";

/**
 * Nobody signs up twice — EXCEPT the applicant who has not finished.
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
 *
 * ⚠️ **AND «مسجَّلُ الدخول» ALONE WAS TOO WIDE, WHICH BROKE A PROMISE PRINTED ON
 * THE PAGE ABOVE THIS ONE.** The teacher application is FOUR steps and says «أربع
 * خطوات، ويمكنك التوقّف والعودة في أي وقت — بياناتك محفوظة»; step 1 CREATES the
 * account, so from that moment the applicant is signed in and every later visit
 * was bounced to the dashboard with «لا حاجة لإنشاء حساب جديد». Coming back was
 * impossible by construction, `TeacherSignupWizard`'s whole restore branch —
 * `GET /teacher/application` then `setStep(application.current_step)` — could
 * never run, and nothing anywhere linked back to the form. Measured on the
 * development database, 2026-09-09: **seventeen applications sitting at step 2
 * or 3**, none of them reachable by its owner. Its sharper half is
 * `changes_requested`: a reviewer asks for corrections, the wizard has a banner
 * ready to show the reason — and the applicant could not get in to make them,
 * so the review loop had no return path at all.
 *
 * ⚠️ The exception asks the SERVER whether the application may still be edited
 * (`editable`, which is `TeacherApplication::isEditable()`), never a status list
 * repeated here. `SaveTeacherApplicationStep` refuses everything that predicate
 * says no to, so a second spelling in the browser would eventually open a form
 * whose every save is refused — a worse dead end than the redirect it replaced.
 */
export default function SignupLayout({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  // Once. `useEffect` re-runs on every render the router causes, and a toast
  // per run stacks the same sentence four deep on one screen.
  const told = useRef(false);

  /*
   | `null` while the question has not been answered yet — the redirect waits for
   | it rather than racing it. It is only ever asked on the one path where the
   | answer can differ, so every other signup page redirects exactly as before,
   | with no request added to it.
   */
  const [resumable, setResumable] = useState<boolean | null>(null);

  useEffect(() => {
    if (user === null) return;

    if (pathname !== "/signup/teacher") {
      setResumable(false);

      return;
    }

    let alive = true;

    api
      .get<{ application: { editable?: boolean } }>("/teacher/application")
      // A refusal is not a resumable application: someone who is not a teacher,
      // or a teacher with no application at all, is redirected as before.
      .then(({ application }) => alive && setResumable(application.editable === true))
      .catch(() => alive && setResumable(false));

    return () => {
      alive = false;
    };
  }, [user, pathname]);

  useEffect(() => {
    if (user === null || told.current || resumable === null || resumable) return;

    told.current = true;

    toast.info("أنت مسجَّل الدخول بالفعل", {
      description: `لا حاجة لإنشاء حساب جديد — نقلناك إلى ${
        isLearner(user) ? "صفحة دراستك" : "لوحتك"
      }. سجّل الخروج أوّلاً إن أردت حساباً آخر.`,
    });

    // `replace`, not `push`: the form is not a place to come back to with the
    // browser's own back button.
    router.replace(panelPathFor(user));
  }, [user, router, resumable]);

  return <>{children}</>;
}
