"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { auth, setToken, clearToken } from "@/lib/api";
import type { User } from "@/lib/types";

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<User>;
  register: (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string }) => Promise<void>;
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
      .catch(() => clearToken())
      .finally(() => setLoading(false));
  }, []);

  const login = async (email: string, password: string) => {
    const { user, token } = await auth.login(email, password);
    setToken(token);
    setUser(user);

    return user;
  };

  const register = async (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string }) => {
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
    <AuthContext.Provider value={{ user, loading, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
