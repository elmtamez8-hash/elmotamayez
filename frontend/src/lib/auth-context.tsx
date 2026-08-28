"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { auth, setToken, setSessionUuid, clearToken, type SignedIn } from "@/lib/api";
import { twoFactor } from "@/lib/two-factor";
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
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

/**
 * Where a user belongs after signing in (FR-012).
 *
 * Students and parents have no workspace, so `/dashboard` — which resolves one —
 * would greet them with an error. They start where the product actually is for
 * them: the marketplace. Teachers and academy accounts keep the existing
 * destination.
 */
export function homePathFor(user: User): string {
  switch (user.platform_role) {
    case "student":
    case "parent":
      return "/teachers";
    default:
      return "/dashboard";
  }
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
  switch (user.platform_role) {
    case "student":
    case "parent":
      return "/enrollments";
    default:
      return "/dashboard";
  }
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

  return (
    <AuthContext.Provider value={{ user, loading, login, completeTwoFactor, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
