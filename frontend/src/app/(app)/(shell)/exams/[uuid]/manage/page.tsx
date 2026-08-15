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
import { ExamItemsPanel } from "@/components/bank/ExamItemsPanel";

export default function ManageExamPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const router = useRouter();

  const [exam, setExam] = useState<Exam | null>(null);
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

  const [confirmExam, setConfirmExam] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([
      api.get<Exam>(`/exams/${uuid}`),
    ])
      .then(([ex]) => {
        setExam(ex);
        setSettings({
          title: ex.title,
          description: ex.description ?? "",
          duration_minutes: String(ex.duration_minutes),
          passing_score: String(ex.passing_score),
          max_attempts: String(ex.max_attempts),
        });
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

      {/* ⚠️ THE INLINE QUESTION FORM THAT WAS HERE CALLED FOUR ROUTES THAT NO
          LONGER EXIST. `/exams/{uuid}/questions` was removed in spec 008: its
          POST created a question with no concept and no Bloom level, and its
          DELETE hard-deleted a question that students had already sat. The
          screen kept rendering and every button 404'd.

          A question belongs to the bank now; the exam merely includes it. */}
      <ExamItemsPanel examUuid={uuid} />

    </div>
  );
}
