"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { auth, setToken, setSessionUuid, clearToken, type SignedIn } from "@/lib/api";
import { twoFactor } from "@/lib/two-factor";
import { isLearner } from "@/lib/dashboard-audience";
import type { User } from "@/lib/types";

/**
 * A password is no longer the whole of signing in.
 *
 * An account with a second factor gets a challenge and no token, so callers
 * have to narrow on this before they can route anyone anywhere.
 */
export type LoginOutcome = { user: User } | { challenge: string };

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<LoginOutcome>;
  completeTwoFactor: (
    challenge: string,
    credential: { code?: string; recovery_code?: string },
  ) => Promise<User>;
  register: (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string; invitation?: string }) => Promise<void>;
  logout: () => Promise<void>;
  /**
   * ⚠️ THE ONE SPELLING OF «THIS TAB NOW HOLDS A SESSION», AND THERE WERE FOUR.
   *
   * `login` and the two-factor exchange went through `finishSignIn` — token,
   * session uuid AND `setUser`. The three signup forms did the first two BY HAND
   * out of `lib/api` and never told this provider, so `user` stayed `null` for
   * the whole tab: the header rendered its signed-OUT state on the page a person
   * had just created their account from, and only a full reload fixed it —
   * because a reload remounts the provider, which then exchanges the token in
   * `localStorage` for a profile. Reported 2026-09-16.
   *
   * `user` is optional because ONE caller has no user to give: teacher step 1
   * answers `{application, token}` and no profile, so the token is adopted and
   * the account is read back. Every other caller passes what the server already
   * sent rather than spending a round trip to be told it again.
   */
  adoptSession: (session: { token: string; session_uuid?: string; user?: User }) => Promise<void>;
  /**
   * إعادةُ قراءةِ الحسابِ من الخادم.
   *
   * ⚠️ صورةُ الحسابِ تُكتَبُ في `‎/settings/profile` وتُقرَأُ في الشريطِ الجانبيِّ
   * وقائمةِ الحساب — من `user` نفسِه، المحمَّلِ مرّةً عندَ الإقلاع. فبلا هذه
   * الدالّةِ يحفظُ صاحبُ الحسابِ صورةً جديدةً، تتغيّرُ في البطاقةِ التي رفعَها
   * وحدَها، **وتبقى القديمةُ في كلِّ صفحةٍ أخرى إلى أن يُعادَ تحميلُ التطبيق**.
   * بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-٠٦.
   *
   * وقراءةٌ كاملةٌ لا تصحيحٌ موضعيٌّ للحقل: الخادمُ هو من يبني `photo_url` (من
   * ملفِّ المدرّسِ أوّلاً ثمّ الطالب)، وتقليدُ ذلك في العميلِ تهجئةٌ ثانيةٌ
   * لقاعدةٍ واحدة.
   */
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

/**
 * Whether this account is on the LEARNING side of the product — a student or a
 * guardian. Derived from {@link dashboardAudience}, the one classifier; see that
 * module for the order and the people each step is there for.
 *
 * ⚠️ IT USED TO BE `platform_role === "student" || "parent"`, and a null role
 * is not only a founder or an officer. `platform_role` is null for dozens of
 * accounts, students a teacher or a seeder created among them, and «not a
 * learner» showed each of those the PUBLIC face of a course they are enrolled in
 * (`CourseOwnership`) while `panelPathFor()` already sent them to
 * `/enrollments`. Teaching and the platform flags decide staff; nothing else does.
 */
export { isLearner };

/** «Does this account teach here?» — see the module for why it is not `isLearner`. */
export { teachesOnPlatform } from "@/lib/teaches-on-platform";

/**
 * Where a user belongs after signing in (FR-012).
 *
 * Students and parents have no workspace, so `/dashboard` — which resolves one —
 * would greet them with an error. They start where the product actually is for
 * them: the marketplace. Teachers and academy accounts keep the existing
 * destination.
 */
export function homePathFor(user: User): string {
  return isLearner(user) ? "/teachers" : "/dashboard";
}

/**
 * Where the signed-in product lives for this person.
 *
 * ⚠️ NOT the same question as {@link homePathFor}. A student's sign-in
 * destination IS the marketplace, so reusing that here would give them a link
 * to the page they are already standing on — which is exactly how a signed-in
 * student ends up with no route into their own enrolments at all. `/dashboard`
 * resolves a workspace and greets them with an error, so theirs is `/enrollments`.
 */
export function panelPathFor(user: User): string {
  return isLearner(user) ? "/enrollments" : "/dashboard";
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = typeof window !== "undefined" ? localStorage.getItem("auth_token") : null;
    if (!token) {
      setLoading(false);
      return;
    }
    auth.me()
      .then(setUser)
      /*
       * ⚠️ A FAILED REQUEST IS NOT A REFUSED TOKEN, and treating the two as one
       * signed people out for free. This used to `clearToken()` on ANY error —
       * including the fetch being ABORTED because the person clicked a link
       * before the page settled, which is not a rare event but the ordinary way
       * a fast reader uses a nav. The abort rejects, the token is deleted from
       * localStorage, and the page they were navigating to loads with no
       * credentials: a sign-out caused by nothing but their own speed, with no
       * message and nothing to retry.
       *
       * The 401 case is already handled one layer down, in `request()`, which
       * clears the token AND says which of the three reasons ended the session.
       * So there is nothing left for this branch to do but keep the token and
       * let the next page ask again — a 500, an offline moment and an abort all
       * recover by themselves.
       *
       * Found by `payments.spec.ts`, whose student walks two pages in a row.
       */
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const finishSignIn = ({ user, token, session_uuid }: SignedIn) => {
    setToken(token);
    setSessionUuid(session_uuid);
    setUser(user);

    return user;
  };

  const adoptSession = async ({
    token,
    session_uuid,
    user: signedIn,
  }: { token: string; session_uuid?: string; user?: User }) => {
    setToken(token);

    if (session_uuid !== undefined) setSessionUuid(session_uuid);

    if (signedIn !== undefined) {
      setUser(signedIn);

      return;
    }

    /*
     | The teacher's first step alone. Swallowed for the reason `refreshUser`
     | swallows: the ACCOUNT EXISTS by the time we are here, so a banner saying
     | otherwise would be a lie about a write that succeeded — the cost of a
     | failure is a stale header until the next navigation, which is exactly the
     | state we are improving on.
     */
    await auth.me().then(setUser).catch(() => {});
  };

  const login = async (email: string, password: string): Promise<LoginOutcome> => {
    const result = await auth.login(email, password);

    if ("two_factor" in result) return { challenge: result.challenge };

    return { user: finishSignIn(result) };
  };

  const completeTwoFactor = async (
    challenge: string,
    credential: { code?: string; recovery_code?: string },
  ) => finishSignIn(await twoFactor.challenge(challenge, credential));

  const register = async (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string; invitation?: string }) => {
    await auth.register(data);
  };

  const logout = async () => {
    try {
      await auth.logout();
    } finally {
      clearToken();
      setUser(null);
    }
  };

  /*
   * ⚠️ وفشلُها يُبتلَعُ عمداً. هذه مُزامَنةٌ تاليةٌ لعملٍ **نجحَ سلفاً**
   * — الصورةُ محفوظةٌ على الخادمِ وقد رأاها صاحبُها في البطاقة — فلافتةُ
   * خطأٍ هنا تقولُ «لم يُحفظ» عن شيءٍ حُفِظ. والثمنُ أنَّ الشريطَ الجانبيَّ
   * يبقى على القديمةِ حتّى التنقّلِ التّالي، وهو أقلُّ ضرراً من سطرٍ أحمرَ كاذِب.
   */
  const refreshUser = async () => {
    await auth.me().then(setUser).catch(() => {});
  };

  return (
    <AuthContext.Provider
      value={{ user, loading, login, completeTwoFactor, register, logout, refreshUser, adoptSession }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
