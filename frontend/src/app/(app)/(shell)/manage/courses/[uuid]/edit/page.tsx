"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
import { use } from "react";

export default function EditCoursePage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();
  const [course, setCourse] = useState<Course | null>(null);
  const [form, setForm] = useState({ title: "", description: "", price: 0, currency: "USD", is_sequential: true });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<Course>(`/courses/${uuid}`)
      .then((c) => {
        setCourse(c);
        setForm({ title: c.title, description: c.description ?? "", price: c.price, currency: c.currency, is_sequential: c.is_sequential });
      })
      .catch((err: unknown) => setError(errorMessage(err, "Could not load this course")))
      .finally(() => setLoading(false));
  }, [uuid]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      await api.put(`/courses/${uuid}`, form);
      router.push(`/manage/courses/${uuid}`);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Update failed";
      setError(msg);
    } finally {
      setSaving(false);
    }
  };

  const handlePublish = async () => {
    try {
      await api.post(`/courses/${uuid}/publish`);
      router.refresh();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Publish failed";
      setError(msg);
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;
  if (!course) return <div className="text-red-500">Course not found</div>;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Edit Course</h2>
        {course.status === "draft" && (
          <button onClick={handlePublish} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700">
            Publish Course
          </button>
        )}
      </div>

      <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
        {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Title</label>
          <input type="text" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required
            className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
          <textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={4}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Price</label>
            <input type="number" step="0.01" min="0" value={form.price}
              onChange={(e) => setForm({ ...form, price: parseFloat(e.target.value) || 0 })}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Currency</label>
            <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
              <option value="USD">USD</option><option value="EUR">EUR</option><option value="SAR">SAR</option><option value="AED">AED</option><option value="EGP">EGP</option>
            </select>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <input type="checkbox" id="seq" checked={form.is_sequential} onChange={(e) => setForm({ ...form, is_sequential: e.target.checked })}
            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          <label htmlFor="seq" className="text-sm text-gray-700">Sequential learning</label>
        </div>
        <div className="flex gap-3">
          <button type="submit" disabled={saving}
            className="rounded-lg bg-indigo-600 px-6 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
            {saving ? "Saving..." : "Save Changes"}
          </button>
          <button type="button" onClick={() => router.back()}
            className="rounded-lg border border-gray-300 px-6 py-2.5 font-medium text-gray-600 transition hover:bg-gray-50">Cancel</button>
        </div>
      </form>
    </div>
  );
}
