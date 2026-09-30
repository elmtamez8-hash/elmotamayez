"use client";

import type { ReactNode } from "react";

import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/lib/auth-context";
import { can } from "@/lib/permissions";

/**
 * A screen the reader may reach but whose every request the server refuses.
 *
 * ⚠️ THE SIDEBAR GATE (`refusedBy`) JUDGES A PATH BY ITS NAV ENTRY, AND A
 * SUB-SCREEN HAS NONE. `/manage/bank` is open to an assistant (`bank.view`), so
 * `/manage/bank/import` inherited that answer while `ImportController` asks
 * `questions.manage` on every read — the page loaded into «تعذّر تحميل
 * البيانات», a 403 dressed as a network fault. Such a page asks its own
 * permission and renders this instead of its body; `reason` says who does the
 * job, so the reader knows it is a split of work and not a broken screen.
 */
export function RefusedState({ reason }: { reason?: ReactNode }) {
  return (
    <Alert tone="warning" title="هذه الصفحة ليست لك">
      {reason ?? "حسابك لا يملك صلاحية فتح هذه الصفحة."}
    </Alert>
  );
}

/**
 * The page's body, or `RefusedState` for a reader without `permission`.
 *
 * ⚠️ THE BODY IS A SEPARATE COMPONENT, AND IT HAS TO BE. The screens behind this
 * start their reads in effects; gating inside them would either still fire the
 * request that 403s or put an early `return` above a hook. Mounted only when
 * allowed, the body never asks.
 */
export function RequirePermission({
  permission,
  reason,
  children,
}: {
  permission: string;
  reason?: ReactNode;
  children: ReactNode;
}) {
  const { user } = useAuth();

  return can(user, permission) ? <>{children}</> : <RefusedState reason={reason} />;
}
