"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import {
  bank,
  bloomLabel,
  difficultyLabel,
  examItems,
  type BankQuestion,
  type ExamItem,
} from "@/lib/bank";

type Draft = { uuid: string; content: string; points: number; points_override: number | null };

/**
 * Which bank questions an exam includes, and in what order.
 *
 * ⚠️ IT EDITS A LOCAL DRAFT AND SAVES THE COMPLETE LIST IN ONE REQUEST. The API
 * takes the whole list every time, for the reason reordering a course tree does:
 * two teachers editing one exam from two tabs each send the change they made,
 * both succeed, and the exam ends up holding neither arrangement. A request per
 * click would also be a request per click.
 *
 * ⚠️ AND THIS REPLACED AN INLINE QUESTION FORM THAT WAS ALREADY BROKEN. The four
 * `/exams/{uuid}/questions` routes it called were removed in spec 008 — they
 * created questions with no tags and hard-deleted ones that had been sat. The
 * screen kept rendering and every button 404'd.
 */
export function ExamItemsPanel({ examUuid }: { examUuid: string }) {
  const [draft, setDraft] = useState<Draft[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState("");

  const [q, setQ] = useState("");
  const [results, setResults] = useState<BankQuestion[]>([]);
  const [searching, setSearching] = useState(false);

  const toDraft = (item: ExamItem): Draft => ({
    uuid: item.question?.uuid ?? "",
    content: item.question?.content ?? "",
    points: item.points,
    points_override: item.points_override,
  });

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    examItems
      .list(examUuid)
      .then((response) => {
        setDraft((response.data ?? []).map(toDraft));
        setDirty(false);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [examUuid]);

  useEffect(load, [load]);

  useEffect(() => {
    if (q.trim() === "") {
      setResults([]);

      return;
    }

    setSearching(true);

    const timer = setTimeout(() => {
      bank
        .questions({ q })
        .then((response) => setResults(response.data ?? []))
        .catch(() => setResults([]))
        .finally(() => setSearching(false));
    }, 300);

    return () => clearTimeout(timer);
  }, [q]);

  const add = (question: BankQuestion) => {
    // A question cannot appear twice in one exam — the server refuses it, and
    // saying so here beats a 422 after the teacher composed twenty rows.
    if (draft.some((d) => d.uuid === question.uuid)) {
      setError("هذا السؤال مضمومٌ إلى الاختبار بالفعل.");

      return;
    }

    setError("");
    setSaved("");
    setDirty(true);
    setDraft((current) => [
      ...current,
      { uuid: question.uuid, content: question.content, points: question.points, points_override: null },
    ]);
  };

  const move = (index: number, by: -1 | 1) => {
    const target = index + by;

    if (target < 0 || target >= draft.length) return;

    setDirty(true);
    setSaved("");
    setDraft((current) => {
      const next = [...current];
      [next[index], next[target]] = [next[target], next[index]];

      return next;
    });
  };

  const save = async () => {
    setSaving(true);
    setError("");
    setSaved("");

    try {
      const response = await examItems.sync(
        examUuid,
        draft.map((d) => ({ uuid: d.uuid, points_override: d.points_override })),
      );

      setDraft((response.data ?? []).map(toDraft));
      setDirty(false);
      setSaved("حُفظت أسئلة الاختبار.");
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed) return <Alert tone="danger" title="تعذّر التحميل">تعذّر جلب أسئلة هذا الاختبار.</Alert>;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="font-semibold text-ink">
          أسئلة الاختبار <bdi>({draft.length})</bdi>
        </h3>
        <Button href="/manage/bank" variant="ghost" size="sm">
          افتح البنك
        </Button>
      </div>

      {error !== "" && <Alert tone="danger" title="تعذّر التنفيذ">{error}</Alert>}
      {saved !== "" && <Alert tone="success" title="تمّ الحفظ">{saved}</Alert>}

      {draft.length === 0 ? (
        <p className="text-sm text-ink-muted">
          لا أسئلة بعد. ابحث في بنكك أدناه وضمّ ما تريد — السؤال يبقى في البنك ويخدم اختباراتٍ
          أخرى معه.
        </p>
      ) : (
        <ol className="space-y-2">
          {draft.map((item, index) => (
            <li key={item.uuid}>
              <Card padding="sm">
                <div className="flex items-start justify-between gap-3">
                  <p className="text-ink">
                    <bdi>{index + 1}</bdi>. {item.content}
                  </p>
                  <div className="flex shrink-0 items-center gap-1">
                    <Badge tone="neutral">
                      <bdi>{item.points}</bdi> درجة
                    </Badge>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => move(index, -1)}
                      disabled={index === 0}
                    >
                      أعلى
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => move(index, 1)}
                      disabled={index === draft.length - 1}
                    >
                      أسفل
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setDirty(true);
                        setSaved("");
                        setDraft((c) => c.filter((_, i) => i !== index));
                      }}
                    >
                      أخرِج
                    </Button>
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ol>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Button onClick={save} disabled={saving || !dirty}>
          {saving ? "جارٍ الحفظ…" : "احفظ الأسئلة"}
        </Button>
        {dirty && (
          <span className="text-sm text-ink-muted">
            تغييرات غير محفوظة — لن تُطبَّق حتى تضغط الحفظ.
          </span>
        )}
      </div>

      {/* ⚠️ "أخرِج" AND NOT "احذف". Removing a question from an exam leaves it in
          the bank, where it may already be serving two other exams. The old
          screen's trash icon called a hard DELETE on the question itself. */}
      <Card as="section">
        <div className="space-y-3">
          <h4 className="font-semibold text-ink">ضمّ من البنك</h4>
          <TextField
            id="bank_search"
            label="ابحث في بنك أسئلتك"
            value={q}
            onChange={setQ}
            placeholder="اكتب كلمةً من السؤال"
          />

          {searching && <p className="text-sm text-ink-muted">جارٍ البحث…</p>}

          {!searching && q.trim() !== "" && results.length === 0 && (
            <p className="text-sm text-ink-muted">
              لا نتائج. <Link href="/manage/bank/new" className="text-primary hover:underline">
                أنشئ سؤالاً جديداً
              </Link>{" "}
              أو{" "}
              <Link href="/manage/bank/import" className="text-primary hover:underline">
                استورد ملفاً
              </Link>
              .
            </p>
          )}

          <ul className="space-y-2">
            {results.map((question) => (
              <li key={question.uuid} className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-sm text-ink">{question.content}</p>
                  <p className="text-xs text-ink-muted">
                    {question.concept?.name ?? "—"} · {difficultyLabel(question.difficulty)} ·{" "}
                    {bloomLabel(question.bloom_level)}
                  </p>
                </div>
                <Button size="sm" variant="secondary" onClick={() => add(question)}>
                  ضمّ
                </Button>
              </li>
            ))}
          </ul>
        </div>
      </Card>
    </div>
  );
}
