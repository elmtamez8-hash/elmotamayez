"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Workspace } from "@/lib/types";
import Link from "next/link";

export default function WorkspacePage() {
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [switching, setSwitching] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Workspace[] }>("/workspaces")
      .then((res) => setWorkspaces(res.data ?? []))
      .catch((err: unknown) => setError(errorMessage(err, "Could not load workspaces")))
      .finally(() => setLoading(false));
  }, []);

  const handleSwitch = async (uuid: string) => {
    setSwitching(uuid);
    try {
      await api.post(`/workspaces/${uuid}/switch`);
      window.location.reload();
    } catch {
      setSwitching(null);
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Workspaces</h2>
        <Link href="/workspaces/new" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
          + New Workspace
        </Link>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        {workspaces.map((ws) => (
          <div key={ws.uuid} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <div className="mb-3 flex items-center justify-between">
              <div>
                <h3 className="font-semibold">{ws.name}</h3>
                <p className="text-sm capitalize text-gray-500">{ws.type}</p>
              </div>
              <div className="flex items-center gap-2">
                {ws.is_current && (
                  <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                    Active
                  </span>
                )}
                {ws.pivot_role && (
                  <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium capitalize text-indigo-600">
                    {ws.pivot_role.replace("-", " ")}
                  </span>
                )}
              </div>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => handleSwitch(ws.uuid)}
                disabled={switching === ws.uuid}
                className="flex-1 rounded-lg bg-gray-100 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 disabled:opacity-50"
              >
                {switching === ws.uuid ? "Switching..." : "Switch"}
              </button>
              {(ws.is_owner || ws.pivot_role === "tenant-owner") && (
                <Link
                  href={`/workspaces/${ws.uuid}/edit`}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
                >
                  Edit
                </Link>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
