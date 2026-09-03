"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import {
  BLOOM_LEVELS,
  DIFFICULTIES,
  bank,
  bloomLabel,
  difficultyLabel,
  questionTypeLabel,
  type BankFilters,
  type BankQuestion,
  type BloomLevel,
  type Concept,
  type Difficulty,
} from "@/lib/bank";

/**
 * The teacher's question bank.
 *
 * ⚠️ THE FILTERS AND THE SEARCH BOX ARE ONE REQUEST, NOT TWO. The server runs
 * them in different engines — filters as an indexed query, free text through the
 * search index — and picks per request. Splitting them here would mean a screen
 * that filters what it already searched, which is the N+1 of relevance: rows the
 * engine paid to return and this page throws away.
 */
export default function BankPage() {
  const [questions, setQuestions] = useState<BankQuestion[]>([]);
  const [concepts, setConcepts] = useState<Concept[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const [q, setQ] = useState("");
  const [concept, setConcept] = useState("");
  const [difficulty, setDifficulty] = useState<Difficulty | "">("");
  const [bloom, setBloom] = useState<BloomLevel | "">("");
  const [showDisabled, setShowDisabled] = useState(false);

  const load = useCallback((filters: BankFilters) => {
    setLoading(true);
    setFailed(false);

    bank
      .questions(filters)
      .then((response) => setQuestions(response.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    bank
      .concepts()
      .then((response) => setConcepts(response.data ?? []))
      .catch(() => setConcepts([]));
  }, []);

  useEffect(() => {
    // Debounced, because the free-text half of this reaches a search engine and
    // a request per keystroke is a request per keystroke however cheap the first
    // one looks.
    const timer = setTimeout(
      () => load({ q, concept, difficulty, bloom, active: showDisabled ? "0" : "1" }),
      300,
    );

    return () => clearTimeout(timer);
  }, [load, q, concept, difficulty, bloom, showDisabled]);

  const columns: Column<BankQuestion>[] = [
    {
      key: "content",
      header: "السؤال",
      /*
       * ⚠️ `text-ink`, NEVER `text-primary` — the maroon is a FILL, not a text
       * colour, and `globals.css` says so beside the token: «Maroon reaches
       * 1.7:1 on #191315 — as TEXT it is unreadable in the dark, which is the
       * whole reason this token exists apart from `--color-primary`». So the
       * question itself — the column a teacher actually reads — was invisible
       * on a dark screen while the badges and the concept beside it were fine.
       * `--color-ink` inverts with the theme (#f5efe9 dark · #2a2224 light), so
       * one class is legible in both; the underline carries the link, which is
       * what the colour was doing badly.
       */
      render: (row) => (
        <Link
          href={`/manage/bank/${row.uuid}`}
          className="text-ink underline-offset-4 hover:underline"
        >
          {row.content.length > 90 ? `${row.content.slice(0, 90)}…` : row.content}
        </Link>
      ),
    },
    {
      key: "concept",
      header: "الفكرة",
      render: (row) => row.concept?.name ?? "—",
    },
    {
      key: "tags",
      header: "الوسوم",
      render: (row) => (
        <div className="flex flex-wrap gap-1">
          <Badge tone="neutral">{questionTypeLabel(row.type)}</Badge>
          <Badge tone={row.difficulty === "hard" ? "danger" : "info"}>
            {difficultyLabel(row.difficulty)}
          </Badge>
          <Badge tone="neutral">{bloomLabel(row.bloom_level)}</Badge>
        </div>
      ),
    },
    {
      key: "usage",
      header: "في اختبارات",
      numeric: true,
      // The number a teacher checks before rewriting a question. One question can
      // sit in three exams, and editing it changes all three.
      render: (row) => row.usage_count ?? 0,
    },
    {
      key: "state",
      header: "الحالة",
      render: (row) =>
        row.is_active ? <Badge tone="success">مفعَّل</Badge> : <Badge tone="warning">معطَّل</Badge>,
    },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">بنك الأسئلة</h1>
          <p className="text-sm text-ink-muted">
            سؤالٌ واحد يخدم كلّ اختباراتك. عدّله مرّةً، ولن تتغيّر درجةُ محاولةٍ سابقة.
          </p>
        </div>
        <div className="flex gap-2">
          <Button href="/manage/bank/import" variant="secondary">
            استيراد من ملف
          </Button>
          <Button href="/manage/bank/new">سؤال جديد</Button>
        </div>
      </header>

      <Card>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <TextField
            id="q"
            label="بحث في نصّ الأسئلة"
            value={q}
            onChange={setQ}
            placeholder="اكتب كلمةً من السؤال"
          />
          <SelectField
            id="concept"
            label="الفكرة"
            value={concept}
            onChange={setConcept}
            placeholder="كلّ الأفكار"
            options={concepts.map((c) => ({
              value: c.uuid,
              label: c.questions_count === undefined ? c.name : `${c.name} (${c.questions_count})`,
            }))}
          />
          <SelectField
            id="difficulty"
            label="الصعوبة"
            value={difficulty}
            onChange={(value) => setDifficulty(value as Difficulty | "")}
            placeholder="كلّ المستويات"
            options={DIFFICULTIES.map((d) => ({ value: d, label: difficultyLabel(d) }))}
          />
          <SelectField
            id="bloom"
            label="المستوى المعرفي"
            value={bloom}
            onChange={(value) => setBloom(value as BloomLevel | "")}
            placeholder="كلّ المستويات"
            options={BLOOM_LEVELS.map((b) => ({ value: b, label: bloomLabel(b) }))}
          />
        </div>

        <div className="mt-4">
          <Button
            variant={showDisabled ? "primary" : "ghost"}
            onClick={() => setShowDisabled((value) => !value)}
          >
            {showDisabled ? "عرض المفعَّل" : "عرض المعطَّل"}
          </Button>
        </div>
      </Card>

      <Table
        columns={columns}
        rows={questions}
        rowKey={(row) => row.uuid}
        caption="أسئلة البنك بوسومها وعدد الاختبارات التي تضمّها"
        state={loading ? "loading" : failed ? "error" : questions.length === 0 ? "empty" : "ready"}
        emptyTitle={showDisabled ? "لا أسئلة معطَّلة" : "لا أسئلة بعد"}
        emptyDescription="ابدأ بسؤالٍ واحد، أو استورد ملفاً فيه مئات."
        emptyAction={<Button href="/manage/bank/import">استيراد من ملف</Button>}
        onRetry={() => load({ q, concept, difficulty, bloom, active: showDisabled ? "0" : "1" })}
      />
    </div>
  );
}
