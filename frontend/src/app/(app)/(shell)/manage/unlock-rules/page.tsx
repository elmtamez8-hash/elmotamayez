"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField, NumberField, SelectField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Course } from "@/lib/types";
import { ruleSummary, unlockRules, type UnlockRule } from "@/lib/unlock-rules";

/**
 * What a student must have done to open the next session (FR-037).
 *
 * ⚠️ THE SCREEN SHOWS WHICH DEFAULT AN OVERRIDE IS REPLACING, and that is the
 * whole reason it lists both tiers at once. A course rule REPLACES the default
 * rather than adding to it — a teacher who assumes the two combine will write a
 * course rule expecting it to tighten things and watch it loosen them, and
 * nothing on a one-rule-at-a-time screen would tell them.
 */
export default function UnlockRulesPage() {
  const [rules, setRules] = useState<UnlockRule[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  const load = useCallback(() => {
    setState("loading");

    Promise.all([
      unlockRules.list(),
      // The teacher's own courses, so a FIRST override can be created. Without
      // this the picker could only offer overrides that already exist, and the
      // first one would be impossible to make from the screen that manages them.
      api.get<{ data: Course[] }>("/courses").catch(() => ({ data: [] as Course[] })),
    ])
      .then(([ruleResponse, courseResponse]) => {
        setRules(ruleResponse.data ?? []);
        setCourses(courseResponse.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const fallback = rules.find((rule) => rule.is_default) ?? null;

  if (state === "loading") return <RowsSkeleton count={3} />;
  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">شرط فتح الحصة التالية</h1>
        <p className="text-sm text-ink-muted">
          ما الذي يجب أن يفعله الطالب في الحصة السابقة قبل أن تُفتح له التالية. قاعدةٌ عامّة
          لكلّ كورساتك، ويجوز أن يغلبها تخصيصٌ لكورسٍ بعينه.
        </p>
      </header>

      <Card>
        <h2 className="mb-3 font-medium text-ink">القاعدة العامّة</h2>
        {fallback === null ? (
          <Alert tone="info" title="لا شرط مضبوط بعد">
            كلّ الحصص مفتوحة. اضبط قاعدةً عامّة لتبدأ في اشتراط الحضور أو الواجب.
          </Alert>
        ) : (
          <p className="text-sm text-ink">{ruleSummary(fallback)}</p>
        )}
      </Card>

      <RuleForm
        courses={courses}
        fallback={fallback}
        overrides={rules.filter((rule) => !rule.is_default)}
        onSaved={load}
      />

      {rules.filter((rule) => !rule.is_default).length > 0 && (
        <Card>
          <h2 className="mb-3 font-medium text-ink">تخصيصات الكورسات</h2>
          <ul className="space-y-3">
            {rules
              .filter((rule) => !rule.is_default)
              .map((rule) => (
                <li key={rule.uuid} className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-ink">{rule.course_title ?? "—"}</p>
                    <p className="text-sm text-ink-muted">{ruleSummary(rule)}</p>
                  </div>
                  <Badge tone="info">يغلب العامّة</Badge>
                </li>
              ))}
          </ul>
        </Card>
      )}
    </div>
  );
}

function RuleForm({
  courses,
  fallback,
  overrides,
  onSaved,
}: {
  courses: Course[];
  fallback: UnlockRule | null;
  overrides: UnlockRule[];
  onSaved: () => void;
}) {
  const [courseUuid, setCourseUuid] = useState("");
  const [attendance, setAttendance] = useState(fallback?.requires_attendance ?? true);
  const [assignment, setAssignment] = useState(fallback?.requires_assignment ?? true);
  const [minScore, setMinScore] = useState(String(fallback?.min_score_pct ?? 0));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const save = async () => {
    setSaving(true);
    setError("");

    try {
      await unlockRules.save({
        course_uuid: courseUuid === "" ? null : courseUuid,
        requires_attendance: attendance,
        requires_assignment: assignment,
        min_score_pct: Number(minScore),
      });

      onSaved();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  const replacing = courseUuid !== "" && fallback !== null;
  const existing = overrides.find((rule) => rule.course_uuid === courseUuid);

  return (
    <Card>
      <h2 className="mb-3 font-medium text-ink">
        {courseUuid === "" ? "اضبط القاعدة العامّة" : "خصّص لهذا الكورس"}
      </h2>

      {error !== "" && <Alert tone="danger" title={error} />}

      <div className="space-y-4">
        <SelectField
          id="course"
          label="النطاق"
          value={courseUuid}
          onChange={setCourseUuid}
          placeholder="كلّ الكورسات (القاعدة العامّة)"
          options={courses.map((course) => ({ value: course.uuid, label: course.title }))}
        />

        {/* ⚠️ THE SENTENCE THAT PREVENTS THE MISREADING. Merging is the intuitive
            assumption and it is wrong: the specific rule replaces the general one
            outright, so a course rule with attendance off turns it off. */}
        {replacing && (
          <Alert tone="warning" title="هذا التخصيص يحلّ محلّ القاعدة العامّة، ولا يُضاف إليها">
            العامّة الآن: {ruleSummary(fallback)}.
            {existing !== undefined && ` وهذا الكورس مخصَّصٌ بالفعل: ${ruleSummary(existing)}.`}
          </Alert>
        )}

        <CheckboxField
          id="attendance"
          label="يشترط حضور الحصة السابقة"
          checked={attendance}
          onChange={setAttendance}
        />

        <CheckboxField
          id="assignment"
          label="يشترط تسليم واجب الحصة السابقة"
          checked={assignment}
          onChange={setAssignment}
        />

        {assignment && (
          <NumberField
            id="min-score"
            label="أقلّ نسبة في الواجب"
            hint="صفر يعني أنّ التسليم وحده يكفي."
            value={minScore}
            onChange={setMinScore}
            min={0}
            max={100}
          />
        )}

        <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
          احفظ
        </Button>
      </div>
    </Card>
  );
}
