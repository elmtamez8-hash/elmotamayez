"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { roleLabel } from "@/lib/labels";
import { useAuth } from "@/lib/auth-context";
import { Alert } from "@/components/ui/Alert";
import { AuthShell } from "@/components/auth/AuthShell";
import { Button } from "@/components/ui/Button";

interface InvitationDetails {
  workspace_name: string;
  email: string;
  role: string;
  role_label: string | null;
  expires_at: string;
  is_expired: boolean;
  is_accepted: boolean;
}

export default function InvitationPage({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = use(params);
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [invitation, setInvitation] = useState<InvitationDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [joining, setJoining] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    api
      .get<InvitationDetails>(`/workspaces/invitations/${token}`)
      .then(setInvitation)
      .catch(() => setError("رابط الدعوة غير صالح."))
      .finally(() => setLoading(false));
  }, [token]);

  const accept = async () => {
    setJoining(true);
    setError("");
    try {
      await api.post(`/workspaces/invitations/${token}/accept`);
      router.push("/dashboard");
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setJoining(false);
    }
  };

  if (loading || authLoading) {
    return (
      <Shell>
        <p className="text-ink-muted">جارٍ تحميل الدعوة…</p>
      </Shell>
    );
  }

  if (!invitation) {
    return (
      <Shell>
        <Alert tone="danger" title={error || "رابط الدعوة غير صالح."} />
      </Shell>
    );
  }

  const role = roleLabel(invitation.role, invitation.role_label);

  if (invitation.is_accepted) {
    return (
      <Shell>
        <p className="text-ink-muted">قُبِلت هذه الدعوة سابقاً.</p>
        <div className="mt-4">
          <Button href="/dashboard" variant="secondary">
            إلى لوحة التحكم
          </Button>
        </div>
      </Shell>
    );
  }

  if (invitation.is_expired) {
    return (
      <Shell>
        <p className="text-ink-muted">
          انتهت صلاحية هذه الدعوة. اطلب من {invitation.workspace_name} إرسال دعوة جديدة.
        </p>
      </Shell>
    );
  }

  return (
    <Shell>
      <p className="text-ink-muted">
        دُعيت للانضمام إلى{" "}
        <span className="font-semibold text-ink">{invitation.workspace_name}</span> بصفة{" "}
        {role}.
      </p>
      <p className="mt-1 text-sm text-ink-muted">
        أُرسلت الدعوة إلى <bdi>{invitation.email}</bdi>.
      </p>

      {error && (
        <div className="mt-4">
          <Alert tone="danger" title={error} />
        </div>
      )}

      {user ? (
        <div className="mt-6">
          <Button
            fullWidth
            loading={joining}
            loadingLabel="جارٍ الانضمام…"
            onClick={accept}
          >
            انضمّ بصفة {role}
          </Button>
        </div>
      ) : (
        <div className="mt-6 space-y-2">
          {/* No account yet? Register first — both routes come back here. */}
          <Button
            /*
             * ⚠️ A STUDENT INVITATION GOES TO THE PUBLIC SIGNUP, NOT HERE.
             * `/register` is the staff door and does not ask for a date of
             * birth, so it cannot compute the guardian gate a minor needs —
             * and the endpoint refuses a student invitation for that reason.
             * `/signup/student` collects it; the invitation is accepted
             * afterwards, signed in.
             */
            href={
              invitation.role === "student"
                ? "/signup/student"
                : `/register?invitation=${token}&email=${encodeURIComponent(invitation.email)}`
            }
            fullWidth
          >
            أنشئ حساباً
          </Button>
          <Button
            href={`/login?invitation=${token}&email=${encodeURIComponent(invitation.email)}`}
            variant="secondary"
            fullWidth
          >
            لديّ حساب بالفعل
          </Button>
        </div>
      )}
    </Shell>
  );
}

/**
 * All five states of this screen — loading, invalid, already accepted, expired,
 * and the invitation itself — wear one frame, which is why the local wrapper
 * stays rather than each branch calling `AuthShell` directly.
 *
 * ⚠️ THE SUBTITLE IS NOT DECORATION HERE. This was the one auth screen that said
 * NOTHING under the mark: someone arriving from an emailed link saw a logo and a
 * card, and had to read the body text to learn what the page was. `AuthShell`
 * makes the line mandatory for exactly that reason — a screen that cannot say
 * what it is for in five words is a screen nobody wrote a purpose for.
 */
function Shell({ children }: { children: React.ReactNode }) {
  return (
    <AuthShell subtitle="دعوة للانضمام">
      <div className="rounded-2xl border border-line bg-surface-raised p-8">
        {children}
      </div>
    </AuthShell>
  );
}
