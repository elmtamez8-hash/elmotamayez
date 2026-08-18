"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField, NumberField, RadioField, SelectField, TextField, TextareaField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import {
  BLOOM_LEVELS,
  DIFFICULTIES,
  QUESTION_TYPES,
  bank,
  bloomLabel,
  difficultyLabel,
  questionTypeLabel,
  type BankQuestion,
  type BloomLevel,
  type Concept,
  type Difficulty,
  type QuestionType,
} from "@/lib/bank";

type OptionDraft = { content: string; is_correct: boolean };

/**
 * One question and its four mandatory tags.
 *
 * ⚠️ IT ALWAYS SENDS THE WHOLE QUESTION, EVEN ON AN EDIT. The API treats a PATCH
 * here as a replacement — `lesson_id`, `explanation` and the options list each
 * carry a meaningful "absent" — so a form that posted only what changed would
 * detach the lesson and delete every option. This is the same choice reordering
 * a course tree made when it required the complete sibling list.
 *
 * The four tags are `required` here AND enforced in the Action behind it. Not
 * redundancy: the importer reaches that Action with no form behind it at all.
 */
export function QuestionForm({ question }: { question?: BankQuestion }) {
  const router = useRouter();

  const [concepts, setConcepts] = useState<Concept[]>([]);
  const [conceptId, setConceptId] = useState(question?.concept?.uuid ?? "");
  const [type, setType] = useState<QuestionType>(question?.type ?? "mcq");
  const [difficulty, setDifficulty] = useState<Difficulty>(question?.difficulty ?? "medium");
  const [bloom, setBloom] = useState<BloomLevel>(question?.bloom_level ?? "understand");
  const [content, setContent] = useState(question?.content ?? "");
  const [points, setPoints] = useState(String(question?.points ?? 1));
  const [explanation, setExplanation] = useState(question?.explanation ?? "");
  const [isActive, setIsActive] = useState(question?.is_active ?? true);

  const [options, setOptions] = useState<OptionDraft[]>(
    question?.options?.map((o) => ({ content: o.content, is_correct: o.is_correct })) ?? [
      { content: "", is_correct: true },
      { content: "", is_correct: false },
    ],
  );

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    bank
      .concepts()
      .then((response) => setConcepts(response.data ?? []))
      .catch(() => setConcepts([]));
  }, []);

  const isEssay = type === "essay";

  const setOption = (index: number, patch: Partial<OptionDraft>) =>
    setOptions((current) => current.map((o, i) => (i === index ? { ...o, ...patch } : o)));

  const save = async () => {
    setSaving(true);
    setError("");
    setErrors({});

    try {
      const payload = {
        concept_id: conceptId,
        type,
        difficulty,
        bloom_level: bloom,
        content,
        points: Number(points) || 1,
        explanation: explanation === "" ? null : explanation,
        is_active: isActive,
        // An essay carries no options at all — and sending the empty list is how
        // a question changed from multiple-choice to essay loses the ones it had.
        options: isEssay
          ? []
          : options
              .filter((o) => o.content.trim() !== "")
              .map((o, index) => ({ ...o, order: index + 1 })),
      };

      const saved = question
        ? await bank.update(question.uuid, payload)
        : await bank.create(payload);

      router.push(`/manage/bank/${saved.data.uuid}`);
      router.refresh();
    } catch (err: unknown) {
      setErrors(fieldErrors(err));
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      {error !== "" && <Alert tone="danger" title="تعذّر الحفظ">{error}</Alert>}

      <Card>
        <div className="space-y-4">
          <TextareaField
            id="content"
            label="نصّ السؤال"
            value={content}
            onChange={setContent}
            required
            error={errors.content}
            rows={4}
          />

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SelectField
              id="concept_id"
              label="الفكرة"
              value={conceptId}
              onChange={setConceptId}
              placeholder="اختر فكرة"
              required
              error={errors.concept_id}
              options={concepts.map((c) => ({ value: c.uuid, label: c.name }))}
            />
            <SelectField
              id="type"
              label="النوع"
              value={type}
              onChange={(value) => setType(value as QuestionType)}
              required
              error={errors.type}
              options={QUESTION_TYPES.map((t) => ({ value: t, label: questionTypeLabel(t) }))}
            />
            <SelectField
              id="difficulty"
              label="الصعوبة"
              value={difficulty}
              onChange={(value) => setDifficulty(value as Difficulty)}
              required
              error={errors.difficulty}
              options={DIFFICULTIES.map((d) => ({ value: d, label: difficultyLabel(d) }))}
            />
            <SelectField
              id="bloom_level"
              label="المستوى المعرفي"
              value={bloom}
              onChange={(value) => setBloom(value as BloomLevel)}
              required
              error={errors.bloom_level}
              options={BLOOM_LEVELS.map((b) => ({ value: b, label: bloomLabel(b) }))}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <NumberField
              id="points"
              label="الدرجة"
              value={points}
              onChange={setPoints}
              min={1}
              required
              error={errors.points}
            />
            <div className="flex items-end">
              <CheckboxField
                id="is_active"
                label="مفعَّل — يظهر عند بناء اختبار جديد"
                checked={isActive}
                onChange={setIsActive}
              />
            </div>
          </div>

          <TextareaField
            id="explanation"
            label="شرح الإجابة (اختياري)"
            value={explanation}
            onChange={setExplanation}
            error={errors.explanation}
            rows={3}
          />
        </div>
      </Card>

      {!isEssay && (
        <Card>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-semibold text-ink">الخيارات</h2>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setOptions((c) => [...c, { content: "", is_correct: false }])}
              >
                أضف خياراً
              </Button>
            </div>

            {errors.options !== undefined && <Alert tone="danger" title="الخيارات">{errors.options}</Alert>}

            {options.map((option, index) => (
              <div key={index} className="flex items-end gap-3">
                <div className="grow">
                  <TextField
                    id={`option-${index}`}
                    label={`الخيار ${index + 1}`}
                    value={option.content}
                    onChange={(value) => setOption(index, { content: value })}
                  />
                </div>
                <div className="pb-3">
                  {/* Exclusive by construction: marking one option correct unmarks
                      the rest, because the answer set the grader compares against
                      holds exactly one id. */}
                  <RadioField
                    id={`correct-${index}`}
                    name="correct-option"
                    label="صحيح"
                    checked={option.is_correct}
                    onChange={() =>
                      setOptions((current) =>
                        current.map((o, i) => ({ ...o, is_correct: i === index })),
                      )
                    }
                  />
                </div>
                {options.length > 2 && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setOptions((c) => c.filter((_, i) => i !== index))}
                  >
                    حذف
                  </Button>
                )}
              </div>
            ))}

            {/* Both ends of the rule fail the same way and the screen says so
                before the server has to. No correct option and every student is
                marked wrong; TWO and the grader — which compares the answer SET —
                demands both taps from a screen that takes one, so again nobody can
                ever be right, and again it reads as the hardest item in the bank. */}
            <p className="text-sm text-ink-muted">
              حدِّد إجابةً صحيحةً واحدةً بالضبط. سؤالٌ بلا إجابةٍ صحيحة — أو بإجابتَين — يخطئ
              فيه كلّ طالب، فيظهر في التحليل كأصعب أسئلة بنكك.
            </p>
          </div>
        </Card>
      )}

      <div className="flex gap-2">
        <Button onClick={save} disabled={saving}>
          {saving ? "جارٍ الحفظ…" : "احفظ"}
        </Button>
        <Button href="/manage/bank" variant="ghost">
          إلغاء
        </Button>
      </div>
    </div>
  );
}
