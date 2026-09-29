"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/lib/auth-context";
import { takeWorkspaceSwitchedNotice } from "@/lib/workspace-guard";

/**
 * «تم تبديل مكان العمل إلى …» — once, on the page load that followed a switch.
 *
 * The reload happened for one of three reasons: this tab pressed «انتقل إليه»,
 * another tab of the browser did (a `storage` nudge), or the server refused a
 * request because another tab or device had (409 `workspace_changed`). In the
 * last two the reader did nothing here, and a screen that silently turned into
 * another place's data is exactly the confusion this guards against — so the
 * page says where they now are.
 *
 * ⚠️ The name comes from the fresh `/auth/me`, never from the note: only the
 * server knows which workspace the account resolved to after the reload.
 */
export function WorkspaceSwitchedNotice() {
  const { user, loading } = useAuth();
  const [shown, setShown] = useState<{ name: string | null } | null>(null);

  useEffect(() => {
    if (loading || user === null || shown !== null) return;

    if (takeWorkspaceSwitchedNotice()) {
      setShown({ name: user.current_workspace?.name ?? null });
    }
  }, [loading, user, shown]);

  if (shown === null) return null;

  return (
    <div className="mb-4">
      <Alert
        tone="info"
        title={shown.name ? `تم تبديل مكان العمل إلى «${shown.name}».` : "تم تبديل مكان العمل."}
      />
    </div>
  );
}
