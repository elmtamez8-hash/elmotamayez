import { api } from "./api";

/**
 * The question bank: browsing it, editing it, and filling it from a file.
 *
 * The types below mirror BankQuestionResource, ConceptResource and
 * ImportReportResource field for field. Read the PHP resource before changing
 * one — a type that claims a field the API does not send renders a blank with no
 * error anywhere.
 *
 * ⚠️ EVERYTHING HERE IS TEACHER-FACING. `is_correct` and `explanation` are the
 * answer key; a student sitting an exam is served the frozen snapshot by a
 * different endpoint with a different shape. Do not reach for these types on a
 * student screen.
 */

export type Difficulty = "easy" | "medium" | "hard";

export type BloomLevel =
  | "unclassified"
  | "remember"
  | "understand"
  | "apply"
  | "analyze"
  | "evaluate"
  | "create";

export type QuestionType = "mcq" | "true_false" | "essay";

export interface QuestionOption {
  id: number;
  content: string;
  is_correct: boolean;
  order: number;
}

export interface BankQuestion {
  uuid: string;
  type: QuestionType;
  difficulty: Difficulty;
  bloom_level: BloomLevel;
  concept?: { uuid: string; name: string };
  lesson?: { uuid: string; title: string } | null;
  is_active: boolean;
  content: string;
  points: number;
  explanation: string | null;
  options?: QuestionOption[];
  /** How many exams include it — absent unless the caller asked for the count. */
  usage_count?: number;
  created_at: string;
}

export interface Concept {
  uuid: string;
  name: string;
  questions_count?: number;
  /** The row the teacher did not create. The screen greys its edit control
   *  rather than letting the request come back 403. */
  is_default: boolean;
}

export type DuplicatePolicy = "skip" | "create";

export type ImportStatus = "queued" | "running" | "done" | "failed";

export interface ImportReportRow {
  line: number;
  reason: string;
  content?: string;
}

export interface ImportReport {
  uuid: string;
  filename: string;
  duplicate_policy: DuplicatePolicy;
  status: ImportStatus;
  total_rows: number;
  imported_count: number;
  skipped_count: number;
  failed_count: number;
  /** Failures and skips only. The successes are the questions themselves. */
  rows: ImportReportRow[];
  failure_reason: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
}

export interface SaveQuestionPayload {
  concept_id: string;
  lesson_id?: string | null;
  type: QuestionType;
  difficulty: Difficulty;
  bloom_level: BloomLevel;
  content: string;
  points: number;
  explanation?: string | null;
  is_active?: boolean;
  options?: { content: string; is_correct: boolean; order?: number }[];
}

export interface BankFilters {
  q?: string;
  concept?: string;
  lesson?: string;
  difficulty?: Difficulty | "";
  bloom?: BloomLevel | "";
  active?: "0" | "1";
}

type Paginated<T> = { data: T[]; meta?: { total: number; current_page: number; last_page: number } };

function query(filters: BankFilters): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }

  const q = params.toString();

  return q ? `?${q}` : "";
}

export const bank = {
  questions: (filters: BankFilters = {}) =>
    api.get<Paginated<BankQuestion>>(`/manage/bank/questions${query(filters)}`),

  question: (uuid: string) => api.get<{ data: BankQuestion }>(`/manage/bank/questions/${uuid}`),

  create: (payload: SaveQuestionPayload) =>
    api.post<{ data: BankQuestion }>("/manage/bank/questions", payload),

  /**
   * ⚠️ SEND THE WHOLE QUESTION, ALWAYS. The API treats a PATCH here as a
   * replacement: `lesson_id`, `explanation` and the options list all carry a
   * meaningful "absent", so a partial payload detaches the lesson and deletes
   * every option. Same reasoning as the course tree's complete sibling list.
   */
  update: (uuid: string, payload: SaveQuestionPayload) =>
    api.patch<{ data: BankQuestion }>(`/manage/bank/questions/${uuid}`, payload),

  /** Disabled when it has been sat, deleted when it has not — the server decides. */
  remove: (uuid: string) => api.delete<{ deleted: boolean }>(`/manage/bank/questions/${uuid}`),

  concepts: () => api.get<{ data: Concept[] }>("/manage/bank/concepts"),

  createConcept: (name: string) =>
    api.post<{ data: Concept }>("/manage/bank/concepts", { name }),

  imports: () => api.get<Paginated<ImportReport>>("/manage/bank/imports"),

  importReport: (uuid: string) => api.get<{ data: ImportReport }>(`/manage/bank/imports/${uuid}`),

  /** Answers 202 with an id; the report is polled at `importReport`. */
  startImport: (file: File, policy: DuplicatePolicy) => {
    const form = new FormData();
    form.append("file", file);
    form.append("duplicate_policy", policy);

    return api.upload<{ data: ImportReport }>("/manage/bank/imports", form);
  },
};

/** The exam's questions, all of them, in order. */
export interface ExamItem {
  uuid: string;
  order: number;
  points_override: number | null;
  points: number;
  question?: BankQuestion;
}

export const examItems = {
  list: (examUuid: string) => api.get<{ data: ExamItem[] }>(`/manage/exams/${examUuid}/items`),

  /**
   * ⚠️ THE COMPLETE LIST, NEVER A PARTIAL EDIT. Two teachers editing one exam
   * from two tabs each send the change they made, both succeed, and the exam
   * holds neither arrangement.
   */
  sync: (examUuid: string, items: { uuid: string; points_override?: number | null }[]) =>
    api.put<{ data: ExamItem[] }>(`/manage/exams/${examUuid}/items`, { items }),
};

const DIFFICULTY_LABELS: Record<Difficulty, string> = {
  easy: "سهل",
  medium: "متوسّط",
  hard: "صعب",
};

const BLOOM_LABELS: Record<BloomLevel, string> = {
  unclassified: "غير مصنّف",
  remember: "تذكّر",
  understand: "فهم",
  apply: "تطبيق",
  analyze: "تحليل",
  evaluate: "تقويم",
  create: "إنشاء",
};

const TYPE_LABELS: Record<QuestionType, string> = {
  mcq: "اختيار من متعدّد",
  true_false: "صواب وخطأ",
  essay: "مقاليّ",
};

const IMPORT_STATUS_LABELS: Record<ImportStatus, string> = {
  queued: "في الانتظار",
  running: "قيد المعالجة",
  done: "اكتمل",
  failed: "تعذّر",
};

export const difficultyLabel = (value: Difficulty): string => DIFFICULTY_LABELS[value] ?? value;
export const bloomLabel = (value: BloomLevel): string => BLOOM_LABELS[value] ?? value;
export const questionTypeLabel = (value: QuestionType): string => TYPE_LABELS[value] ?? value;
export const importStatusLabel = (value: ImportStatus): string => IMPORT_STATUS_LABELS[value] ?? value;

export const DIFFICULTIES = Object.keys(DIFFICULTY_LABELS) as Difficulty[];
export const BLOOM_LEVELS = Object.keys(BLOOM_LABELS) as BloomLevel[];
export const QUESTION_TYPES = Object.keys(TYPE_LABELS) as QuestionType[];
