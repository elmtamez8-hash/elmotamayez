"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import type { Workspace } from "@/lib/types";
import { useRouter } from "next/navigation";

export default function NewWorkspacePage() {
  const router = useRouter();
  const [form, setForm] = useState({ name: "", type: "academy", slug: "" });
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const ws = await api.post<Workspace>("/workspaces", form);
      await api.post(`/workspaces/${ws.uuid}/switch`);
      router.push("/dashboard");
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to create workspace";
      setError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="mx-auto max-w-md space-y-6">
      <h2 className="text-2xl font-bold">Create New Workspace</h2>
      <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
        {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Name</label>
          <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required
            className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Type</label>
          <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            <option value="academy">Academy</option>
            <option value="school">School</option>
            <option value="university">University</option>
            <option value="company">Company</option>
          </select>
        </div>
        <button type="submit" disabled={loading}
          className="w-full rounded-lg bg-indigo-600 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
          {loading ? "Creating..." : "Create Workspace"}
        </button>
      </form>
    </div>
  );
}
