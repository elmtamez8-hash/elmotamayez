"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Exam } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import {
  CheckboxField,
  NumberField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";
import { CheckIcon, TrashIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

interface Question {
  id: number;
  type: string;
  content: string;
  points: number;
  options: Array<{ id: number; content: string; is_correct: boolean }>;
}

const BLANK_QUESTION = {
  content: "",
  options: [
    { content: "", correct: false },
    { content: "", correct: false },
  ],
};

export default function ManageExamPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const router = useRouter();

  const [exam, setExam] = useState<Exam | null>(null);
  const [questions, setQuestions] = useState<Question[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");

  const [settings, setSettings] = useState({
    title: "",
    description: "",
    duration_minutes: "60",
    passing_score: "60",
    max_attempts: "3",
  });
  const [settingsFields, setSettingsFields] = useState<Record<string, string>>({});
  const [savingSettings, setSavingSettings] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [publishing, setPublishing] = useState(false);

  const [newQ, setNewQ] = useState(BLANK_QUESTION);
  const [questionFields, setQuestionFields] = useState<Record<string, string>>({});
  const [adding, setAdding] = useState(false);

  /** id awaiting a second click before it is actually deleted. */
  const [confirmQuestion, setConfirmQuestion] = useState<number | null>(null);
  const [confirmExam, setConfirmExam] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([
      api.get<Exam>(`/exams/${uuid}`),
      api.get<{ data: Question[] }>(`/exams/${uuid}/questions`),
    ])
      .then(([ex, qs]) => {
        setExam(ex);
        setSettings({
          title: ex.title,
          description: ex.description ?? "",
          duration_minutes: String(ex.duration_minutes),
          passing_score: String(ex.passing_score),
          max_attempts: String(ex.max_attempts),
        });
        setQuestions(qs.data ?? []);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  const saveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingSettings(true);
    setError("");
    setSettingsFields({});

    try {
      const updated = await api.put<Exam>(`/exams/${uuid}`, {
        title: settings.title,
        description: settings.description,
        duration_minutes: parseInt(settings.duration_minutes, 10) || 60,
        passing_score: parseInt(settings.passing_score, 10) || 60,
        max_attempts: parseInt(settings.max_attempts, 10) || 3,
      });
      setExam(updated);
      setShowSettings(false);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setSettingsFields(found);
      else setError(userMessage(err));
    } finally {
      setSavingSettings(false);
    }
  };

  const addQuestion = async (e: React.FormEvent) => {
    e.preventDefault();
    setAdding(true);
    setError("");
    setQuestionFields({});

    if (!newQ.options.some((o) => o.correct)) {
      setError("حدّد الإجابة الصحيحة قبل إضافة السؤال.");
      setAdding(false);
      return;
    }

    try {
      await api.post(`/exams/${uuid}/questions`, {
        type: "mcq",
        content: newQ.content,
        points: 1,
        options: newQ.options.map((o, i) => ({
          content: o.content,
          is_correct: o.correct,
          order: i + 1,
        })),
      });
      setNewQ(BLANK_QUESTION);
      load();
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setQuestionFields(found);
      else setError(userMessage(err));
    } finally {
      setAdding(false);
    }
  };

  const publish = async () => {
    setPublishing(true);
    setError("");
    try {
      await api.post(`/exams/${uuid}/publish`);
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setPublishing(false);
    }
  };

  const deleteQuestion = async (questionId: number) => {
    setError("");
    try {
      await api.delete(`/exams/${uuid}/questions/${questionId}`);
      setConfirmQuestion(null);
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    }
  };

  const deleteExam = async () => {
    setError("");
    try {
      await api.delete(`/exams/${uuid}`);
      router.push("/exams");
    } catch (err: unknown) {
      setError(userMessage(err));
      setConfirmExam(false);
    }
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed || !exam) return <ErrorState onRetry={load} />;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-2xl font-bold text-ink">{exam.title}</h2>
            <StatusBadge status={exam.status} />
          </div>
          <p className="text-ink-muted">
            <bdi>{exam.duration_minutes}</bdi> دقيقة · النجاح{" "}
            <bdi>{exam.passing_score}%</bdi>
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowSettings(!showSettings)}
          >
            {showSettings ? "أغلق الإعدادات" : "إعدادات الاختبار"}
          </Button>

          {exam.status === "draft" && (
            <Button
              size="sm"
              loading={publishing}
              loadingLabel="جارٍ النشر…"
              onClick={publish}
            >
              انشر الاختبار
            </Button>
          )}

          {/* Two clicks, not window.confirm(): a native dialog blocks the page
              and cannot be translated. */}
          {confirmExam ? (
            <>
              <Button size="sm" variant="danger" onClick={deleteExam}>
                أكّد حذف الاختبار
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirmExam(false)}>
                إلغاء
              </Button>
            </>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => setConfirmExam(true)}>
              حذف
            </Button>
          )}
        </div>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {showSettings && (
        <Card as="section">
          <form onSubmit={saveSettings} className="space-y-4">
            <h3 className="font-semibold text-ink">إعدادات الاختبار</h3>

            <TextField
              id="exam_title"
              label="العنوان"
              value={settings.title}
              onChange={(v) => setSettings({ ...settings, title: v })}
              error={settingsFields.title}
              required
            />

            <TextareaField
              id="exam_description"
              label="الوصف"
              value={settings.description}
              onChange={(v) => setSettings({ ...settings, description: v })}
              error={settingsFields.description}
              rows={2}
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <NumberField
                id="duration_minutes"
                label="المدة بالدقائق"
                value={settings.duration_minutes}
                onChange={(v) => setSettings({ ...settings, duration_minutes: v })}
                error={settingsFields.duration_minutes}
                min={1}
              />
              <NumberField
                id="passing_score"
                label="درجة النجاح ٪"
                value={settings.passing_score}
                onChange={(v) => setSettings({ ...settings, passing_score: v })}
                error={settingsFields.passing_score}
                min={0}
                max={100}
              />
              <NumberField
                id="max_attempts"
                label="أقصى عدد محاولات"
                value={settings.max_attempts}
                onChange={(v) => setSettings({ ...settings, max_attempts: v })}
                error={settingsFields.max_attempts}
                min={1}
              />
            </div>

            <Button type="submit" loading={savingSettings} loadingLabel="جارٍ الحفظ…">
              احفظ الإعدادات
            </Button>
          </form>
        </Card>
      )}

      <section className="space-y-3">
        <h3 className="font-semibold text-ink">
          الأسئلة <bdi>({questions.length})</bdi>
        </h3>

        {questions.length === 0 ? (
          <p className="text-sm text-ink-muted">
            لا أسئلة بعد. أضف أول سؤال من النموذج أدناه.
          </p>
        ) : (
          questions.map((q, idx) => (
            <Card key={q.id} padding="sm">
              <div className="flex items-start justify-between gap-3">
                <p className="mb-2 font-medium text-ink">
                  <bdi>{idx + 1}</bdi>. {q.content}
                </p>

                {confirmQuestion === q.id ? (
                  <div className="flex shrink-0 gap-1">
                    <Button size="sm" variant="danger" onClick={() => deleteQuestion(q.id)}>
                      أكّد
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setConfirmQuestion(null)}
                    >
                      إلغاء
                    </Button>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => setConfirmQuestion(q.id)}
                    aria-label={`احذف السؤال ${idx + 1}`}
                    className="shrink-0 rounded p-1 text-ink-muted transition hover:text-danger-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <TrashIcon />
                  </button>
                )}
              </div>

              <ul className="space-y-1">
                {q.options.map((o) => (
                  <li
                    key={o.id}
                    className={`flex items-center gap-2 text-sm ${
                      o.is_correct ? "font-medium text-secondary-ink" : "text-ink-muted"
                    }`}
                  >
                    {o.is_correct ? (
                      <CheckIcon className="h-3.5 w-3.5 shrink-0" title="الإجابة الصحيحة" />
                    ) : (
                      <span aria-hidden="true" className="w-3.5 text-center">
                        ·
                      </span>
                    )}
                    {o.content}
                  </li>
                ))}
              </ul>
            </Card>
          ))
        )}
      </section>

      <Card as="section">
        <form onSubmit={addQuestion} className="space-y-4">
          <h3 className="font-semibold text-ink">أضف سؤالاً</h3>

          <TextareaField
            id="question_content"
            label="نصّ السؤال"
            value={newQ.content}
            onChange={(v) => setNewQ({ ...newQ, content: v })}
            error={questionFields.content}
            rows={2}
            required
          />

          <fieldset className="space-y-2">
            <legend className="mb-1 block text-sm font-medium text-ink">
              الخيارات — علّم الإجابة الصحيحة
            </legend>

            {newQ.options.map((opt, i) => (
              <div key={i} className="flex items-center gap-2">
                <CheckboxField
                  id={`option_correct_${i}`}
                  label={<span className="sr-only">الخيار {i + 1} صحيح</span>}
                  checked={opt.correct}
                  onChange={(checked) => {
                    const opts = [...newQ.options];
                    opts[i] = { ...opt, correct: checked };
                    setNewQ({ ...newQ, options: opts });
                  }}
                />
                <div className="flex-1">
                  <label htmlFor={`option_${i}`} className="sr-only">
                    نصّ الخيار {i + 1}
                  </label>
                  <input
                    id={`option_${i}`}
                    type="text"
                    value={opt.content}
                    onChange={(e) => {
                      const opts = [...newQ.options];
                      opts[i] = { ...opt, content: e.target.value };
                      setNewQ({ ...newQ, options: opts });
                    }}
                    required
                    placeholder={`الخيار ${i + 1}`}
                    className="w-full rounded-xl border border-line bg-surface-raised px-3 py-1.5 text-sm text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                  />
                </div>
              </div>
            ))}

            <Button
              size="sm"
              variant="ghost"
              onClick={() =>
                setNewQ({
                  ...newQ,
                  options: [...newQ.options, { content: "", correct: false }],
                })
              }
            >
              أضف خياراً
            </Button>
          </fieldset>

          <Button type="submit" loading={adding} loadingLabel="جارٍ الإضافة…">
            أضف السؤال
          </Button>
        </form>
      </Card>
    </div>
  );
}
