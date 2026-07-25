"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, errorMessage } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";

interface InvitationDetails {
  workspace_name: string;
  email: string;
  role: string;
  expires_at: string;
  is_expired: boolean;
  is_accepted: boolean;
}

export default function InvitationPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [invitation, setInvitation] = useState<InvitationDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [joining, setJoining] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<InvitationDetails>(`/workspaces/invitations/${token}`)
      .then(setInvitation)
      .catch(() => setError("This invitation link is not valid."))
      .finally(() => setLoading(false));
  }, [token]);

  const handleAccept = async () => {
    setJoining(true);
    setError("");
    try {
      await api.post(`/workspaces/invitations/${token}/accept`);
      router.push("/dashboard");
    } catch (err: unknown) {
      setError(errorMessage(err, "Could not accept the invitation"));
    } finally {
      setJoining(false);
    }
  };

  if (loading || authLoading) {
    return <Shell><p className="text-gray-400">Loading invitation...</p></Shell>;
  }

  if (!invitation) {
    return <Shell><p className="text-red-600">{error || "This invitation link is not valid."}</p></Shell>;
  }

  const role = invitation.role.replace(/-/g, " ");

  if (invitation.is_accepted) {
    return (
      <Shell>
        <p className="text-gray-600">This invitation has already been accepted.</p>
        <Link href="/dashboard" className="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline">
          Go to dashboard
        </Link>
      </Shell>
    );
  }

  if (invitation.is_expired) {
    return (
      <Shell>
        <p className="text-gray-600">
          This invitation expired. Ask {invitation.workspace_name} to send a new one.
        </p>
      </Shell>
    );
  }

  return (
    <Shell>
      <p className="text-gray-600">
        You have been invited to join <span className="font-semibold text-gray-900">{invitation.workspace_name}</span>{" "}
        as <span className="capitalize">{role}</span>.
      </p>
      <p className="mt-1 text-sm text-gray-500">Invitation sent to {invitation.email}.</p>

      {error && <div className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      {user ? (
        <button
          onClick={handleAccept}
          disabled={joining}
          className="mt-6 w-full rounded-lg bg-indigo-600 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"
        >
          {joining ? "Joining..." : `Join as ${role}`}
        </button>
      ) : (
        <div className="mt-6 space-y-2">
          {/* No account yet? Register first — both routes come back here. */}
          <Link
            href={`/register?invitation=${token}&email=${encodeURIComponent(invitation.email)}`}
            className="block w-full rounded-lg bg-indigo-600 py-2.5 text-center font-medium text-white transition hover:bg-indigo-700"
          >
            Create an account
          </Link>
          <Link
            href={`/login?invitation=${token}&email=${encodeURIComponent(invitation.email)}`}
            className="block w-full rounded-lg border border-gray-300 py-2.5 text-center font-medium text-gray-700 transition hover:bg-gray-50"
          >
            I already have an account
          </Link>
        </div>
      )}
    </Shell>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <h1 className="text-3xl font-bold text-indigo-600">Mteatch</h1>
        </div>
        <div className="rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-200">{children}</div>
      </div>
    </div>
  );
}
