"use client";

import Link from "next/link";

import { panelPathFor, useAuth } from "@/lib/auth-context";

/**
 * «حجز حصة تجريبية» — but only for somebody who has no account yet.
 *
 * ⚠️ THE LINK WENT TO A SIGNUP FORM UNCONDITIONALLY, so a signed-in TEACHER
 * pressing it on a colleague's card was shown «أنشئ حساب طالب» — a form for a
 * role they cannot hold, on an account they already have. It was written for the
 * only visitor the flow was ever designed around: a brand-new one.
 *
 * ⚠️ AND THE SIGNED-IN BRANCH IS A DIFFERENT SCREEN, NOT A DISABLED BUTTON. There
 * is no trial-booking flow for an existing account anywhere in this product yet —
 * the signup page's own promise is «after creating the account you will return to
 * the teacher's page to complete the booking», and that return lands on this same
 * button. So the honest answer is the panel, where the person's real relationships
 * live, and NOT a control that looks pressable and leads nowhere.
 *
 * A client component so the card above it can stay a server one: `useAuth` reads
 * `localStorage`, and making `TeacherCard` a client component would ship the whole
 * list's markup to the browser to answer one question about the viewer.
 *
 * ⚠️ `user` IS NULL ON THE SERVER AND ON THE FIRST CLIENT PAINT, so the guest
 * branch is what renders first for everyone — deliberately, and the same
 * assumption `SiteHeader` already documents. A signed-in visitor sees the signup
 * label for one frame; the alternative is a spinner where a button belongs.
 */
export function TrialCta({
  teacherUuid,
  variant = "card",
}: {
  teacherUuid: string;
  /**
   * `card` sits in a row beside «عرض الملف»; `profile` is the stacked panel;
   * `bar` is the fixed strip that only exists below `lg`.
   */
  variant?: "card" | "profile" | "bar";
}) {
  const { user } = useAuth();

  const filled = {
    card: "flex-[1.4] rounded-full bg-accent px-3 py-2.5 text-center text-sm font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
    profile:
      "mb-3 block rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
    bar: "flex-1 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
  }[variant];

  if (user === null) {
    return (
      <Link href={`/signup/student?teacher=${teacherUuid}&trial=1`} className={filled}>
        {variant === "bar" ? "احجز الآن" : "حجز حصة تجريبية"}
      </Link>
    );
  }

  // The fixed bar has room for one control and must not be empty on a phone: it
  // is the only booking affordance on that screen, so it becomes the way in.
  if (variant === "bar") {
    return (
      <Link href={panelPathFor(user)} className={filled}>
        افتح لوحتك
      </Link>
    );
  }

  /*
   * ⚠️ NOTHING AT ALL ON A CARD, NOT A SECOND «عرض الملف». The card already
   * carries that link beside this slot, and rendering the same label twice in one
   * row makes the reader compare two buttons that do the same thing. The sibling
   * is `flex-1`, so it simply takes the width back.
   */
  if (variant === "card") {
    return null;
  }

  return (
    <div className="mb-3 rounded-2xl border border-line p-4 text-sm text-ink-muted">
      <p className="mb-2">
        أنت مسجَّل الدخول بالفعل. الحجز والمتابعة يتمّان من لوحتك.
      </p>
      <Link href={panelPathFor(user)} className="font-semibold text-primary-ink underline">
        افتح لوحتك
      </Link>
    </div>
  );
}
