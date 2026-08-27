"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

import { FacetBar, type Facet } from "@/components/filters/FacetBar";
import { MistakesIcon, PracticeIcon } from "@/components/icons";
import { PracticeRunner } from "@/components/practice/PracticeRunner";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { difficultyLabel, DIFFICULTIES, type Difficulty } from "@/lib/bank";
import { userMessage } from "@/lib/errors";
import { mistakes } from "@/lib/mistakes";
import { practice, type PracticeFilterOptions, type PracticePaper } from "@/lib/practice";

/**
 * The student builds their own paper.
 *
 * ⚠️ THIS PAGE DID NOT WORK AT ALL, AND THE FILTER ON IT HAD NEVER HAD OPTIONS.
 * Two separate defects, both invisible to the suite and both measured on
 * 2026-08-27 with a fixture built the way the product actually creates a student:
 *
 *  1. `POST /practice/exams` answered **403** to every real student.
 *     `BuildSelfExamRequest::authorize()` asked `can(ATTEMPTS_SUBMIT)`, and spatie
 *     runs in team mode — a student is a member of no workspace, so the team id is
 *     null and EVERY `can()` for them is false. The enrolment is the authorisation
 *     now, which is what the sibling «اختبرني في أخطائي» endpoint always did.
 *  2. The concept select was filled from `/manage/bank/concepts`, a TEACHER route
 *     that answers a student `403`. It rendered empty for everybody who could see
 *     it, and the old comment here called that «degrading to any concept».
 *
 * ⚠️ AND THE PICKERS COME FROM THE POOL THE PAPER IS DRAWN FROM. `/practice/filters`
 * derives them from `PracticePool::questionsFor()` — the same query — so an
 * offered concept always has questions behind it and one that would refuse is
 * never offered.
 */
export default function PracticePage() {
  const [options, setOptions] = useState<PracticeFilterOptions | null>(null);
  const [hasStanding, setHasStanding] = useState(false);
  const [loading, setLoading] = useState(true);

  const [teacher, setTeacher] = useState("");
  const [course, setCourse] = useState("");
  const [subject, setSubject] = useState("");
  const [concept, setConcept] = useState("");
  const [difficulty, setDifficulty] = useState<Difficulty | "">("");
  const [count, setCount] = useState("10");
  const [duration, setDuration] = useState("15");

  const [paper, setPaper] = useState<PracticePaper | null>(null);
  const [building, setBuilding] = useState(false);
  const [error, setError] = useState("");

  /*
    Re-read whenever the teacher changes: courses and concepts belong to ONE
    bank, so the lists a second teacher offers are different lists — keeping the
    first teacher's would offer a concept the new bank has never heard of.
  */
  const loadOptions = useCallback(() => {
    setLoading(true);

    practice
      .filters(teacher)
      .then((response) => setOptions(response.data))
      .catch(() => setOptions(null))
      .finally(() => setLoading(false));
  }, [teacher]);

  useEffect(loadOptions, [loadOptions]);

  useEffect(() => {
    // The notebook answers whether there is anything to revise. Asked here so
    // the link below appears on real data rather than on the hope of some.
    mistakes
      .filters()
      .then((response) => setHasStanding(response.has_standing === true))
      .catch(() => setHasStanding(false));
  }, []);

  // Clearing the course also clears the concept: a concept chosen inside a
  // course is not necessarily in the pool once the course filter goes.
  const facets = useMemo<Facet[]>(
    () => [
      {
        key: "teacher",
        label: "المدرّس",
        placeholder: "اختر مدرّساً",
        options: options?.teachers ?? [],
      },
      /*
        ⚠️ THE SUBJECT AND THE COURSE ARE TWO AXES, NOT ONE NAMED TWICE. One
        teacher may run «الرياضيات ٩» and «الرياضيات ١٠» as separate courses; a
        student revising the subject wants both, and a student revising for one
        course's test wants only it.
      */
      { key: "subject", label: "المادّة", placeholder: "كلّ المواد", options: options?.subjects ?? [] },
      { key: "course", label: "الكورس", placeholder: "كلّ الكورسات", options: options?.courses ?? [] },
      { key: "concept", label: "الفكرة", placeholder: "كلّ الأفكار", options: options?.concepts ?? [] },
    ],
    [options],
  );

  const setFacet = (key: string, value: string) => {
    if (key === "teacher") {
      setTeacher(value);
      setSubject("");
      setCourse("");
      setConcept("");

      return;
    }

    if (key === "subject") {
      setSubject(value);
      // The course list is not filtered by subject, so a course chosen under the
      // old one would silently contradict the new — cleared rather than left to
      // produce a pair that can only refuse.
      setCourse("");

      return;
    }

    if (key === "course") setCourse(value);
    if (key === "concept") setConcept(value);
  };

  const build = () => {
    setBuilding(true);
    setError("");

    practice
      .build({
        count: parseInt(count, 10),
        duration_minutes: parseInt(duration, 10),
        teacher,
        course,
        subject,
        concept_id: concept,
        difficulty,
      })
      .then((response) => setPaper(response.data))
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setBuilding(false));
  };

  if (paper !== null) {
    return (
      <div className="space-y-6">
        <h2 className="flex items-center gap-2 text-2xl font-bold text-ink">
          <PracticeIcon className="h-6 w-6 text-primary-ink" />
          ورقة تدريب
        </h2>

        {/*
          ⚠️ FR-023: SHORT IS AN ANSWER, NOT A FAILURE — and it is SAID. A paper
          that quietly returns four questions for a request of ten teaches the
          student that the number they typed does nothing, and they read the mark
          out of four as a mark out of ten.
        */}
        {paper.delivered_count < paper.requested_count && (
          <Alert tone="info" title="الورقة أقصر ممّا طلبت">
            طلبت <bdi>{paper.requested_count}</bdi> سؤالاً، وما يطابق اختيارك في بنك مدرّسك{" "}
            <bdi>{paper.delivered_count}</bdi>. وسّع الفكرة أو الصعوبة لورقةٍ أطول.
          </Alert>
        )}

        <PracticeRunner paper={paper} onRestart={() => setPaper(null)} />
      </div>
    );
  }

  const canBuild = options !== null && options.has_questions && (options.teachers.length <= 1 || teacher !== "");

  return (
    <div className="space-y-6">
      <h2 className="flex items-center gap-2 text-2xl font-bold text-ink">
        <PracticeIcon className="h-6 w-6 text-primary-ink" />
        درّب نفسك
      </h2>

      <p className="text-sm text-ink-muted">
        اختر ما تريد التدرّب عليه، فتُبنى لك ورقةٌ من دروسك المسجَّل فيها — تُصحَّح فور تسليمها
        ومعها الشروح، ولا تُحتسب في درجاتك.
      </p>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر بناء الورقة">
          {error}
        </Alert>
      )}

      {loading ? (
        <RowsSkeleton count={2} />
      ) : options === null || !options.has_questions ? (
        /*
          ⚠️ A SENTENCE, NOT AN EMPTY FORM. A bank with nothing in it used to
          render four selects and a button that answered «لا أسئلة تطابق ما
          اخترت» — a refusal the student reads as being about their own choices.
        */
        <EmptyState
          title="لا أسئلة للتدريب بعد"
          description="يظهر هنا بنك مدرّسك متى أضاف أسئلةً على دروس المواد المسجَّل فيها."
        />
      ) : (
        <>
          <FacetBar
            facets={facets}
            values={{ teacher, subject, course, concept }}
            onChange={setFacet}
            onReset={() => {
              setTeacher("");
              setSubject("");
              setCourse("");
              setConcept("");
            }}
          />

          {/*
            The teacher is asked for only when there is a real choice — the
            server refuses a paper it cannot attribute to one bank, so a screen
            that let them press «ابنِ الورقة» first would answer a refusal.
          */}
          {options.teachers.length > 1 && teacher === "" && (
            <Alert tone="info" title="اختر المدرّس أولاً">
              الورقة تُبنى من بنك مدرّسٍ واحد، فاختر مَن تريد التدرّب على مادّته.
            </Alert>
          )}

          <Card>
            <div className="grid gap-4 sm:grid-cols-3">
              <SelectField
                id="difficulty"
                label="الصعوبة"
                value={difficulty}
                onChange={(value) => setDifficulty(value as Difficulty | "")}
                placeholder="كلّ المستويات"
                options={DIFFICULTIES.map((item) => ({ value: item, label: difficultyLabel(item) }))}
              />
              <SelectField
                id="count"
                label="عدد الأسئلة"
                value={count}
                onChange={setCount}
                options={["5", "10", "15", "20", "30"].map((value) => ({ value, label: value }))}
              />
              <SelectField
                id="duration"
                label="المدّة بالدقائق"
                value={duration}
                onChange={setDuration}
                options={["10", "15", "30", "45"].map((value) => ({ value, label: value }))}
              />
            </div>

            <div className="mt-4">
              <Button onClick={build} loading={building} loadingLabel="جارٍ البناء…" disabled={!canBuild}>
                ابنِ الورقة
              </Button>
            </div>
          </Card>
        </>
      )}

      {/*
        ⚠️ THE OTHER WAY TO PRACTISE, WHICH THIS PAGE NEVER LINKED TO.
        `/practice/from-mistakes` shipped with spec 008 and the only route to it
        was the notebook — a student who came here to revise was offered a random
        paper and never told that the platform can test them on exactly what they
        got wrong. Drawn only when there IS something standing, so it is never a
        link to an empty run.
      */}
      {hasStanding && (
        <Link
          href="/mistakes"
          className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 text-sm text-ink transition-colors hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <MistakesIcon className="h-5 w-5 shrink-0 text-primary-ink" />
          <span>
            <span className="font-semibold">أو اختبر نفسك في أخطائك.</span>{" "}
            <span className="text-ink-muted">
              دفترك يحمل أسئلةً أخطأت فيها — التدرّب عليها هو ما يُخرِجها منه.
            </span>
          </span>
        </Link>
      )}
    </div>
  );
}
