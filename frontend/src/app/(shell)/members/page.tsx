"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Workspace } from "@/lib/types";

interface Member {
  uuid: string;
  name: string;
  email: string;
  role: string;
}

/** The workspace the API is acting in, falling back to the only membership. */
function pickCurrent(workspaces: Workspace[] | undefined): Workspace | undefined {
  return workspaces?.find((w) => w.is_current) ?? workspaces?.[0];
}

export default function MembersPage() {
  const [members, setMembers] = useState<Member[]>([]);
  const [loading, setLoading] = useState(true);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState("student");
  const [inviting, setInviting] = useState(false);
  const [error, setError] = useState("");

  const loadMembers = () => {
    api.get<{ data: Workspace[] }>("/workspaces")
      .then(async (res) => {
        const current = pickCurrent(res.data);
        if (current) {
          const detail = await api.get<{ data: Member[] }>(`/workspaces/${current.uuid}/members`);
          setMembers(detail.data ?? []);
        }
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadMembers(); }, []);

  const handleInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    setInviting(true);
    setError("");
    try {
      const ws = await api.get<{ data: Workspace[] }>("/workspaces");
      const current = pickCurrent(ws.data);
      if (!current) throw new Error("No active workspace");
      await api.post(`/workspaces/${current.uuid}/invitations`, { email: inviteEmail, role: inviteRole });
      setInviteEmail("");
      loadMembers();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to invite";
      setError(msg);
    } finally {
      setInviting(false);
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Workspace Members</h2>

      <form onSubmit={handleInvite} className="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
        {error && <div className="w-full rounded-lg bg-red-50 p-2 text-sm text-red-600">{error}</div>}
        <div className="flex-1">
          <label className="mb-1 block text-sm font-medium text-gray-700">Email</label>
          <input
            type="email"
            value={inviteEmail}
            onChange={(e) => setInviteEmail(e.target.value)}
            required
            placeholder="member@example.com"
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Role</label>
          <select
            value={inviteRole}
            onChange={(e) => setInviteRole(e.target.value)}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
            <option value="teacher">Teacher</option>
            <option value="assistant-teacher">Assistant Teacher</option>
            <option value="student">Student</option>
          </select>
        </div>
        <button
          type="submit"
          disabled={inviting}
          className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"
        >
          {inviting ? "Inviting..." : "Invite"}
        </button>
      </form>

      <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
        <table className="w-full text-sm">
          <thead className="border-b border-gray-200 bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Email</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Role</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {members.map((m) => (
              <tr key={m.uuid} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{m.name}</td>
                <td className="px-4 py-3 text-gray-500">{m.email}</td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium capitalize text-indigo-600">
                    {m.role.replace(/-/g, " ")}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
