"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { PracticeRunner } from "@/components/practice/PracticeRunner";
import { bank, difficultyLabel, DIFFICULTIES, type Concept, type Difficulty } from "@/lib/bank";
import { userMessage } from "@/lib/errors";
import { practice, type PracticePaper } from "@/lib/practice";

/**
 * The student builds their own paper.
 *
 * ⚠️ THE POOL IS THEIR ACTIVE ENROLMENT, and the copy says so. A student whose
 * term ended keeps their membership — it is how they still read their
 * certificates — so "no questions" here has a cause worth naming, or the screen
 * reads as broken to the person it just stopped working for.
 */
export default function PracticePage() {
  const [concepts, setConcepts] = useState<Concept[]>([]);
  const [concept, setConcept] = useState("");
  const [difficulty, setDifficulty] = useState<Difficulty | "">("");
  const [count, setCount] = useState("10");
  const [duration, setDuration] = useState("15");

  const [paper, setPaper] = useState<PracticePaper | null>(null);
  const [building, setBuilding] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    // The concept list is the teacher's taxonomy, read through the bank's own
    // endpoint. It answers 403 for anyone without `bank.view` — a student — so
    // the filter degrades to "any concept" rather than breaking the page.
    bank
      .concepts()
      .then((response) => setConcepts(response.data ?? []))
      .catch(() => setConcepts([]));
  }, []);

  const build = async () => {
    setBuilding(true);
    setError("");

    try {
      const response = await practice.build({
        count: parseInt(count, 10),
        duration_minutes: parseInt(duration, 10),
        concept_id: concept,
        difficulty,
      });

      setPaper(response.data);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setBuilding(false);
    }
  };

  if (paper !== null) {
    return (
      <div className="mx-auto max-w-2xl space-y-6">
        <h1 className="text-xl font-semibold text-ink">ورقة تدريب</h1>
        <PracticeRunner paper={paper} onRestart={() => setPaper(null)} />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">درّب نفسك</h1>
        <p className="text-sm text-ink-muted">
          اختر فكرةً وصعوبةً وعدداً، فتُبنى لك ورقةٌ من دروسك المسجَّل فيها — تُصحَّح فور
          تسليمها ومعها الشروح، ولا تُحتسب في درجاتك.
        </p>
      </header>

      {error !== "" && <Alert tone="danger" title={error} />}

      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            id="concept"
            label="الفكرة"
            value={concept}
            onChange={setConcept}
            placeholder="كلّ الأفكار"
            options={concepts.map((item) => ({ value: item.uuid, label: item.name }))}
          />
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
            options={["5", "10", "15", "20"].map((value) => ({ value, label: value }))}
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
          <Button onClick={build} loading={building} loadingLabel="جارٍ البناء…">
            ابنِ الورقة
          </Button>
        </div>
      </Card>
    </div>
  );
}
