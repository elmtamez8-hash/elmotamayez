"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";

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
 * ⚠️ `user` IS NULL ON THE SERVER AND ON THE FIRST CLIENT PAINT, and there is no
 * server-side answer to be had: the token lives in `localStorage`, so no Next
 * middleware and no server component can read it. The guard is therefore a
 * client one by construction — and it HOLDS THE RENDER rather than letting the
 * form paint and pulling it back (see the condition at the bottom of this file).
 * This paragraph used to say the opposite, and described the flash as acceptable;
 * a full registration form that blinks and vanishes reads as a fault, not a
 * courtesy.
 *
 * ⛔ AND HOLDING A RENDER IS NOT A GUARD. The browser decides what it draws; a
 * `curl` with a valid bearer token draws nothing and posts anyway. The door is
 * `RefuseAuthenticated` (`guest.only`) on all four account-minting routes — and
 * it went on the day this hold was written, because until then a signed-in
 * account could mint a second one from its own session with nothing to stop it.
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
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  // Once. `useEffect` re-runs on every render the router causes, and a toast
  // per run stacks the same sentence four deep on one screen.
  const told = useRef(false);

  /*
   | ⛔ THE GUARD IS FOR SOMEBODY WHO **ARRIVES** SIGNED IN — NEVER FOR A SESSION
   | CREATED ON THIS PAGE, AND UNTIL 2026-09-16 NOTHING SAID SO BECAUSE NOTHING
   | HAD TO: the three forms never told `AuthProvider` about the account they had
   | just made, so `user` stayed `null` right through the redirect and this
   | effect could not fire. The stale header was one symptom; **this was the
   | other**, and fixing the first without this turns it into a worse defect than
   | the one it fixes.
   |
   | Two things break the moment a fresh registration is read as "already signed
   | in". `/signup/parent/children` — the step a parent is HANDED by the form one
   | screen earlier, whose own page says «only reachable with a session» — sits
   | under this layout, so the parent is bounced to their panel with «لا حاجة
   | لإنشاء حساب جديد» and can never name a child. Measured in a real browser on
   | production. And on the student form `router.replace(panelPathFor(user))`
   | here RACES the form's own `router.push(safeNext(next, …))`, so the teacher
   | page or the `/subscribe` selection that started the signup — FR-005, the
   | promise printed on the page itself — is lost to whichever navigation lands
   | second.
   |
   | `loading` is what separates the two, and it is the only thing that can:
   | `user` is null on the server, on the first paint, AND for a guest, so the
   | value alone says nothing. The provider answers `loading = false` exactly
   | once the token in `localStorage` has been exchanged for a profile — or found
   | not to be there — so the FIRST settled answer is who walked in. Anything
   | after it happened here.
   |
   | ⚠️ IT WAS A REF «because nothing renders from it», AND SOMETHING RENDERS
   | FROM IT NOW — the hold at the bottom of this file. A ref read during render
   | does not re-render when it changes, so the condition would have frozen on
   | its first value for ever. State, and the effects read the same one value.
   |
   | Scoped to this layout's mount, which is the signup flow's own lifetime: it
   | survives `/signup/parent` → `/signup/parent/children` and is asked again
   | from scratch by anybody who leaves `/signup` and comes back.
   */
  const [arrivedSignedIn, setArrivedSignedIn] = useState<boolean | null>(null);

  useEffect(() => {
    if (loading || arrivedSignedIn !== null) return;

    setArrivedSignedIn(user !== null);
  }, [loading, user, arrivedSignedIn]);

  /*
   | `null` while the question has not been answered yet — the redirect waits for
   | it rather than racing it. It is only ever asked on the one path where the
   | answer can differ, so every other signup page redirects exactly as before,
   | with no request added to it.
   */
  const [resumable, setResumable] = useState<boolean | null>(null);

  useEffect(() => {
    if (user === null || arrivedSignedIn !== true) return;

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
  }, [user, pathname, arrivedSignedIn]);

  useEffect(() => {
    if (user === null || arrivedSignedIn !== true) return;
    if (told.current || resumable === null || resumable) return;

    told.current = true;

    toast.info("أنت مسجَّل الدخول بالفعل", {
      description:
        "لا حاجة لإنشاء حساب جديد — أعدناك إلى الرئيسيّة. سجّل الخروج أوّلاً إن أردت حساباً آخر.",
    });

    // `replace`, not `push`: the form is not a place to come back to with the
    // browser's own back button.
    router.replace("/");
  }, [user, router, resumable, arrivedSignedIn]);

  /*
    ⛔ **والنموذجُ لا يُرسَمُ أصلاً لمن سيُنقَل، وقد كانَ يُرسَمُ ثمّ يُسحَب.**

    الوثيقةُ أعلاه تصفُ ذلك بوصفِه نتيجةً معروفة: «النموذجُ يُرسَمُ للجميعِ
    والتحويلُ يتبعُ». لكنّ ما يراه صاحبُ الحسابِ هو استمارةُ تسجيلٍ كاملةٌ تومضُ
    ثمّ تختفي — ووميضُ شاشةٍ لم تكنْ له يُقرَأُ عطلاً، لا لطفاً.

    ثلاثُ حالاتٍ تُمسِكُ الرسم، وواحدةٌ فقط تُطلِقُه:
    - `loading`: السؤالُ لم يُجَبْ بعد. ونافذتُه معدومةٌ عمليّاً لضيفٍ بلا رمزٍ
      في `localStorage` — لا شيءَ يُبادَل، فالجوابُ يأتي في الإطارِ نفسِه.
    - `arrivedSignedIn === true` مع `resumable !== true`: نحنُ في طريقِنا إلى
      الرئيسيّة، فالرسمُ عملٌ يُلقى.
    - `resumable === null` على مسارِ المدرّس: السؤالُ في الشبكةِ الآن.

    ⚠️ و`arrivedSignedIn` صارَ حالةً لا `ref`. كانَ مرجعاً لأنّ «لا شيءَ يُرسَمُ
    منه» — وقد صارَ يُرسَمُ منه، ومرجعٌ يُقرَأُ في الرسمِ لا يُعيدُ الرسمَ حينَ
    يتغيّر، فيبقى الشرطُ على قيمتِه الأولى للأبد.

    ⚠️ ولا يُمسِكُ شيئاً لمن أنشأَ حسابَه **على هذه الصفحة**: `arrivedSignedIn`
    عندَه `false`، وهو الفرقُ الذي تشرحُه الفقرةُ الطويلةُ أعلاه — وبدونِه يختفي
    نموذجُ «أبنائي» في وجهِ وليِّ الأمرِ لحظةَ إنشاءِ حسابِه.

    و`null` لا شاشةَ انتظار: هذا تخطيطٌ متداخِل، فترويسةُ الموقعِ وتذييلُه
    مرسومانِ فوقَه وتحتَه — والفراغُ لحظةً أهدأُ من «جارٍ التحقّق» تومضُ لكلِّ
    ضيفٍ يفتحُ الصفحةَ لأوّلِ مرّة.
  */
  if (loading || (arrivedSignedIn === true && resumable !== true)) return null;

  return <>{children}</>;
}
