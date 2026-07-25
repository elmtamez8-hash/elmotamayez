"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Exam } from "@/lib/types";
import { useRouter } from "next/navigation";
import { use } from "react";

interface Question {
  id: number;
  type: string;
  content: string;
  points: number;
  options: Array<{ id: number; content: string; is_correct: boolean }>;
}

export default function ManageExamPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();
  const [exam, setExam] = useState<Exam | null>(null);
  const [questions, setQuestions] = useState<Question[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [editForm, setEditForm] = useState({ title: "", description: "", duration_minutes: 60, passing_score: 60, max_attempts: 3 });
  const [editing, setEditing] = useState(false);
  const [showEdit, setShowEdit] = useState(false);

  const [newQ, setNewQ] = useState({ content: "", options: [{ content: "", correct: false }, { content: "", correct: false }] });
  const [adding, setAdding] = useState(false);

  const loadData = () => {
    Promise.all([
      api.get<Exam>(`/exams/${uuid}`),
      api.get<{ data: Question[] }>(`/exams/${uuid}/questions`),
    ]).then(([ex, qs]) => {
      setExam(ex);
      setEditForm({ title: ex.title, description: ex.description ?? "", duration_minutes: ex.duration_minutes, passing_score: ex.passing_score, max_attempts: ex.max_attempts });
      setQuestions(qs.data ?? []);
    })
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadData(); }, [uuid]);

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setEditing(true);
    setError("");
    try {
      const updated = await api.put<Exam>(`/exams/${uuid}`, editForm);
      setExam(updated);
      setShowEdit(false);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Update failed");
    } finally {
      setEditing(false);
    }
  };

  const handleAddQuestion = async (e: React.FormEvent) => {
    e.preventDefault();
    setAdding(true);
    setError("");
    try {
      await api.post(`/exams/${uuid}/questions`, {
        type: "mcq",
        content: newQ.content,
        points: 1,
        options: newQ.options.map((o, i) => ({ content: o.content, is_correct: o.correct, order: i + 1 })),
      });
      setNewQ({ content: "", options: [{ content: "", correct: false }, { content: "", correct: false }] });
      loadData();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to add question";
      setError(msg);
    } finally {
      setAdding(false);
    }
  };

  const handlePublish = async () => {
    try { await api.post(`/exams/${uuid}/publish`); loadData(); }
    catch (err: unknown) { setError(err instanceof Error ? err.message : "Publish failed"); }
  };

  const handleDeleteQuestion = async (questionId: number) => {
    if (!confirm("Delete this question?")) return;
    try {
      await api.delete(`/exams/${uuid}/questions/${questionId}`);
      loadData();
    } catch {
      // ignore
    }
  };

  const handleDeleteExam = async () => {
    if (!confirm("Delete this exam and all its questions? This cannot be undone.")) return;
    try {
      await api.delete(`/exams/${uuid}`);
      router.push("/exams");
    } catch {
      // ignore
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;
  if (!exam) return <div className="text-red-500">Exam not found</div>;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold">{exam.title}</h2>
          <p className="text-gray-600">{exam.duration_minutes} min · Pass: {exam.passing_score}%</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setShowEdit(!showEdit)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
            {showEdit ? "Cancel" : "Edit Settings"}
          </button>
          {exam.status === "draft" && (
            <button onClick={handlePublish} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700">
              Publish Exam
            </button>
          )}
          <button onClick={handleDeleteExam} className="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50">
            Delete
          </button>
        </div>
      </div>

      {showEdit && (
        <form onSubmit={handleSaveSettings} className="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
          <h3 className="font-semibold">Exam Settings</h3>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Title</label>
            <input type="text" value={editForm.title} onChange={(e) => setEditForm({ ...editForm, title: e.target.value })} required
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
            <textarea value={editForm.description} onChange={(e) => setEditForm({ ...editForm, description: e.target.value })} rows={2}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
          </div>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Duration (min)</label>
              <input type="number" min="1" value={editForm.duration_minutes} onChange={(e) => setEditForm({ ...editForm, duration_minutes: parseInt(e.target.value) || 60 })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Passing (%)</label>
              <input type="number" min="0" max="100" value={editForm.passing_score} onChange={(e) => setEditForm({ ...editForm, passing_score: parseInt(e.target.value) || 60 })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Max attempts</label>
              <input type="number" min="1" value={editForm.max_attempts} onChange={(e) => setEditForm({ ...editForm, max_attempts: parseInt(e.target.value) || 3 })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
            </div>
          </div>
          <button type="submit" disabled={editing}
            className="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
            {editing ? "Saving..." : "Save Settings"}
          </button>
        </form>
      )}

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="space-y-3">
        {questions.map((q, idx) => (
          <div key={q.id} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
            <div className="flex items-start justify-between">
              <p className="mb-2 font-medium">{idx + 1}. {q.content}</p>
              <button
                onClick={() => handleDeleteQuestion(q.id)}
                className="rounded p-1 text-gray-400 transition hover:text-red-600"
                title="Delete question"
              >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                </svg>
              </button>
            </div>
            <div className="space-y-1">
              {q.options.map((o) => (
                <div key={o.id} className={`text-sm ${o.is_correct ? "font-medium text-green-600" : "text-gray-500"}`}>
                  {o.is_correct ? "✓ " : "• "}{o.content}
                </div>
              ))}
            </div>
          </div>
        ))}
        {questions.length === 0 && <p className="text-sm text-gray-500">No questions yet. Add one below.</p>}
      </div>

      <form onSubmit={handleAddQuestion} className="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
        <h3 className="font-semibold">Add Question</h3>
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Question text</label>
          <textarea value={newQ.content} onChange={(e) => setNewQ({ ...newQ, content: e.target.value })} required rows={2}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
        </div>
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-700">Options (check the correct one)</label>
          {newQ.options.map((opt, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="checkbox" checked={opt.correct} onChange={(e) => {
                const opts = [...newQ.options]; opts[i] = { ...opt, correct: e.target.checked };
                setNewQ({ ...newQ, options: opts });
              }} className="h-4 w-4 rounded border-gray-300 text-indigo-600" />
              <input type="text" value={opt.content} onChange={(e) => {
                const opts = [...newQ.options]; opts[i] = { ...opt, content: e.target.value };
                setNewQ({ ...newQ, options: opts });
              }} required placeholder={`Option ${i + 1}`}
                className="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none" />
            </div>
          ))}
          <button type="button" onClick={() => setNewQ({ ...newQ, options: [...newQ.options, { content: "", correct: false }] })}
            className="text-sm text-indigo-600 hover:underline">+ Add option</button>
        </div>
        <button type="submit" disabled={adding}
          className="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
          {adding ? "Adding..." : "Add Question"}
        </button>
      </form>
    </div>
  );
}
