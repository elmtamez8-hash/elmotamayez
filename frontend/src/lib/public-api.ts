/**
 * Server-side client for the public marketplace API.
 *
 * Deliberately separate from lib/api.ts: that one reads a bearer token from
 * localStorage, which does not exist on the server. Public pages are rendered on
 * the server so a crawler that runs no JavaScript still sees the content
 * (SC-016), so they cannot go through the browser client.
 */

const API_BASE =
  process.env.MARKETPLACE_API_URL ?? "http://localhost:8000/api/v1";

/** Matches config('marketplace.cache_ttl_seconds') — both derive from SC-010. */
const REVALIDATE_SECONDS = 60;

export type TrustBand = "high" | "medium" | "low" | "building";

export type Taxonomy = {
  slug: string;
  name_ar: string;
  icon?: string | null;
  teachers_count?: number;
};

/**
 * One year of school (spec 022 · FR-001ب) — what a STUDENT picks.
 *
 * `grade_level_slug` is the broad stage it belongs to, sent so a screen can
 * group the list without a second request. The student's own stage is DERIVED
 * from this on the server; nothing here writes it.
 */
export type SchoolYearOption = {
  slug: string;
  name_ar: string;
  grade_level_slug: string;
};

export type TeacherCard = {
  uuid: string;
  // The public URL segment. `uuid` stays because the write endpoints — posting
  // a review, pre-filling signup — are still keyed by it.
  slug: string | null;
  name: string;
  headline: string | null;
  photo_url: string | null;
  subjects: Taxonomy[];
  grade_levels: Taxonomy[];
  years_experience: number;
  teaching_languages: string[];
  /*
   * ⚠️ NO `hourly_rate` AND NO `currency` — the API stopped sending both
   * (spec 006, FR-021و). The platform is the seller: the student pays a
   * cost-plus total and the teacher is paid an approved settlement rate, and
   * publishing the second beside the first is the whole equation. The column
   * still exists; it is the teacher's own input, not a public field.
   */
  average_rating: number | null;
  reviews_count: number;
  trust_score: number | null;
  trust_score_band: TrustBand;
  is_verified: boolean;
  available_now: boolean;
};

export type ReviewItem = {
  student_display_name: string;
  // Null far more often than not — a student who never uploaded one. Initials
  // are the designed fallback, not a placeholder waiting to be replaced.
  student_avatar_url: string | null;
  rating: number;
  comment: string | null;
  created_at: string;
};

export type AvailabilityItem = {
  day_of_week: number;
  start_time: string;
  end_time: string;
};

export type CourseCard = {
  uuid: string;
  title: string;
  cover_url: string | null;
  teacher: {
    uuid: string;
    slug: string | null;
    name: string;
    photo_url: string | null;
  } | null;
  type: "individual" | "group" | "recorded";
  lessons_count: number;
  duration_seconds: number;
  /*
   * ⚠️ NO PRICE ON A BROWSE CARD (FR-021هـ). The price belongs on the buyable
   * unit's own page, which is the course page — a card in a list is a browsing
   * surface. Unlike the teacher's rate this is a placement rule, not a secret.
   */
  average_rating: number | null;
  enrolled_count: number;
  is_bestseller: boolean;
};

/*
 * Spec 023 — the course's OWN page, which is not the card with more fields.
 *
 * ⚠️ IT CARRIES THE PRICE AND THE CARD DOES NOT (006 · FR-021هـ): the price
 * belongs to the buyable unit, and until 023 the unit had no page. And no item
 * in `curriculum` carries a uuid or a media path — the title, the kind and the
 * duration are the whole promise a visitor is deciding on.
 */
export type CurriculumItem = {
  title: string;
  kind: string;
  duration_seconds: number | null;
};

export type CurriculumChapter = {
  title: string;
  items: CurriculumItem[];
};

export type CurriculumSection = {
  title: string;
  chapters: CurriculumChapter[];
};

/*
 * ⚠️ `seats_left` IS OPTIONAL, NOT NULLABLE, AND THE DIFFERENCE IS THE FEATURE.
 * A group with no declared ceiling has no number of seats left: the key is
 * ABSENT (FR-012). Typed `number | null` the component would render «٠ مقاعد»
 * for «غير محدود» — the opposite of what it means — and TypeScript would not
 * object.
 *
 * `schedule` is a list of short Arabic labels («السبت 16:00»), read from the
 * same directory the student's own group picker uses. Empty means nothing has
 * been scheduled yet, which the screen says out loud.
 */
export type CohortSummary = {
  uuid: string;
  name: string;
  description: string | null;
  status: "open" | "full" | "closed";
  schedule: string[];
  seats_left?: number;
  /**
   * The SERVER's answer to «could I join this?» (027 · FR-002).
   *
   * ⚠️ NEVER RE-DERIVED FROM `status` AND `seats_left`. It is
   * `Cohort::isJoinable()` — the same predicate the purchase route asks — so the
   * card cannot invite somebody into a group the door then refuses. Deriving it
   * here would be one question with two spellings, which is exactly the defect
   * a subscribe button turns into a 422 the visitor cannot act on.
   */
  is_joinable: boolean;
};

export type CourseDetail = {
  uuid: string;
  slug: string | null;
  title: string;
  description: string | null;
  cover_url: string | null;
  subject: { slug: string; name_ar: string; icon: string | null } | null;
  grade_level: string | null;
  teacher: {
    uuid: string;
    slug: string | null;
    name: string;
    photo_url: string | null;
    trust_score: number | null;
    trust_score_band: TrustBand;
  } | null;
  type: "individual" | "group" | "recorded";
  lessons_count: number;
  duration_seconds: number;
  price_minor: number | null;
  currency: string | null;
  average_rating: number | null;
  enrolled_count: number;
  curriculum: CurriculumSection[];
  cohorts: CohortSummary[];
  /**
   * How long a private hour in this course lasts (023 · FR-016أ). Null means the
   * platform default — never «no private sessions», which is why the form reads
   * it with `??` rather than hiding itself when it is absent.
   */
  private_session_minutes: number | null;
  /**
   * Whether the private-subscription invitation may be drawn at all (027 · FR-003).
   *
   * ⚠️ THE SERVER ANSWERS THIS BECAUSE NOTHING HERE CAN. It is true only when
   * the teacher has declared hours AND has a priced one-to-one plan — and plans
   * sit behind `auth:sanctum` while this page is anonymous and server-rendered.
   * A button drawn without it is pressed and then refused, which is the very
   * thing 023's refusal branch was written to avoid.
   *
   * One boolean leaks nothing: «no plan», «switched off» and «awaiting a price»
   * all answer false, the same collapse the purchase refusal performs so that
   * nobody learns which teachers have a plan waiting to be priced.
   */
  private_subscription_available: boolean;
  /**
   * The promo video's ID on the teacher's own channel (018 · FR-006).
   *
   * ⚠️ An ID, never a URL and never a ready-made embed address: the embed
   * address is built from it in `PromoVideoButton`, which is the only place
   * that knows the host. Null means «no button» — the server sends null both
   * for «no video» and for «awaiting review», and the difference is none of a
   * visitor's business.
   */
  promo_video_id: string | null;
};

export type TeacherDetail = TeacherCard & {
  bio: string | null;
  qualifications: string[];
  stats: {
    students_taught: number;
    completed_sessions: number;
    response_rate: number | null;
    attendance_rate: number | null;
  };
  trust_score_factors: Record<string, number> | null;
  courses: CourseCard[];
  reviews: {
    average: number | null;
    total: number;
    distribution: Record<string, number>;
    items: ReviewItem[];
  };
  availability: AvailabilityItem[];
  faqs: { question: string; answer: string }[];
};

export type Paginated<T> = {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    filters?: Record<string, string>;
  };
};

export type MarketplaceStats = {
  students: number;
  teachers: number;
  sessions: number;
  satisfaction_rate: number;
};

export type HomePayload = {
  stats: MarketplaceStats;
  featured_teachers: TeacherCard[];
  featured_courses: CourseCard[];
  subjects: Taxonomy[];
  // Real reviews, not authored copy. This used to be a `{name, role, quote}`
  // shape holding three invented quotes signed with real Qatari family names;
  // it now carries the same review shape the teacher's own page publishes, and
  // arrives empty until a student writes one.
  testimonials: {
    student_display_name: string;
    rating: number;
    comment: string;
    created_at: string;
    // The teacher the review is ABOUT. The reviewer stays initials-only —
    // PublicFieldAllowlist::REVIEW carries the reason.
    teacher_slug: string | null;
    teacher_name: string | null;
    teacher_photo_url: string | null;
  }[];
  faqs: { question: string; answer: string }[];
};

export type ArticleTaxonomy = { slug: string; name: string };

export type ArticleCard = {
  uuid: string;
  slug: string;
  title: string;
  excerpt: string | null;
  /*
   * ⚠️ On the CARD as well as the article: the index is what renders twelve
   * images, and a cover that reached the article page alone would leave every
   * card bare with nothing failing anywhere. Built from a relative path by the
   * API — the column itself never travels.
   */
  cover_url: string | null;
  published_at: string;
  updated_at: string;
  category?: ArticleTaxonomy | null;
  tags?: ArticleTaxonomy[];
};

/** سؤالٌ وجوابُه. الزوجُ الناقصُ مُصفّىً في الخلفيّة — never rendered half. */
export type ArticleFaq = { question: string; answer: string };

export type ArticleDetail = ArticleCard & {
  /*
   * Rendered from Markdown per response, never stored. The API strips raw HTML
   * at the parse rather than escaping it, so the allowlist IS the Markdown
   * feature set — there is no sanitiser configuration on this side to get wrong,
   * and no `body` field to accidentally render instead.
   */
  body_html: string;
  seo_title: string | null;
  seo_description: string | null;
  canonical_url: string | null;
  /*
   * ⚠️ الخلاصةُ ليست `excerpt`. المقتطفُ إغراءٌ يُقرَأُ تحتَ العنوان في القائمة؛
   * هذه فقرةٌ مكتفيةٌ بنفسِها يقتبسُها محرّكُ الإجابةِ بلا ما حولَها — ولذلك
   * تُعرَضُ في صندوقٍ أعلى المقالِ وتُرسَلُ في `abstract`: ما يُقرَأُ هو ما
   * يُقتبَس.
   */
  summary: string | null;
  faq: ArticleFaq[];
  /** يُبنى منه وسمُ `robots`. القيمةُ الافتراضيّةُ في القاعدةِ `true`. */
  is_indexable: boolean;
  related_teachers: TeacherCard[];
  related_courses: CourseCard[];
};

export class NotFoundError extends Error {}

async function get<T>(
  path: string,
  params?: Record<string, string | undefined>,
): Promise<T> {
  const url = new URL(`${API_BASE}${path}`);

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value !== undefined && value !== "") url.searchParams.set(key, value);
  }

  const response = await fetch(url, {
    headers: { Accept: "application/json" },
    next: { revalidate: REVALIDATE_SECONDS },
  });

  if (response.status === 404) {
    throw new NotFoundError(`Not found: ${path}`);
  }

  if (!response.ok) {
    throw new Error(
      `Marketplace API ${response.status} for ${path}`,
    );
  }

  return (await response.json()) as T;
}

export const publicApi = {
  home: () => get<HomePayload>("/marketplace/home"),

  stats: () => get<MarketplaceStats>("/marketplace/stats"),

  // The stage narrows the list: subjects are what teachers OF THAT STAGE
  // actually teach, derived server-side rather than from a stored mapping.
  subjects: (gradeLevel?: string) =>
    get<Taxonomy[]>("/marketplace/subjects", { grade_level: gradeLevel }),

  gradeLevels: () => get<Taxonomy[]>("/marketplace/grade-levels"),

  // Spec 011 · FR-042 — the registration form needs this before there is an
  // account, so it is a public read like the two above it.
  regions: () => get<Taxonomy[]>("/marketplace/regions"),

  /*
   * Spec 022 · FR-002 — the SIGNUP vocabulary, and deliberately not the three
   * reads above.
   *
   * ⚠️ `/marketplace/subjects` DROPS EVERY ENTRY WITH NO PUBLICLY LISTED
   * TEACHER. Right for a filter bar, and on a required signup field a circular
   * lock: no listed teacher means no subject in the list means the first teacher
   * on the platform can never apply. These three answer the whole active
   * vocabulary and take no parameters.
   */
  signupSubjects: () => get<Taxonomy[]>("/signup/subjects"),

  signupGradeLevels: () => get<Taxonomy[]>("/signup/grade-levels"),

  schoolYears: () => get<SchoolYearOption[]>("/signup/school-years"),

  teachers: (params: Record<string, string | undefined>) =>
    get<Paginated<TeacherCard>>("/marketplace/teachers", params),

  // Accepts a slug or a uuid: the API resolves both, and an old shared link
  // is a uuid.
  teacher: (key: string) =>
    get<{ data: TeacherDetail }>(
      `/marketplace/teachers/${encodeURIComponent(key)}`,
    ),

  courses: (params: Record<string, string | undefined>) =>
    get<Paginated<CourseCard>>("/marketplace/courses", params),

  /*
   * ⚠️ A uuid, NEVER a slug — unlike `teacher()` one line up, which takes
   * either. `courses.slug` is unique per (workspace_id, slug), i.e. inside one
   * workspace only, so two teachers naming a course «الرياضيات ٣» produce the
   * same slug and a public route with no workspace to read cannot tell them
   * apart. The slug still travels in the payload, for display.
   */
  course: (uuid: string) =>
    get<{ data: CourseDetail }>(
      `/marketplace/courses/${encodeURIComponent(uuid)}`,
    ),

  /*
   * The blog. `per_page` is capped server-side at 200 — the sitemap is the only
   * caller that asks for a big page, and it walks them rather than asking for
   * everything at once.
   */
  articles: (params: Record<string, string | undefined> = {}) =>
    get<Paginated<ArticleCard>>("/public/articles", params),

  // `encodeURIComponent` because the slugs are Arabic: an unencoded one is not a
  // legal request target, and the path segment is the whole address here.
  article: (slug: string) =>
    get<{ data: ArticleDetail }>(
      `/public/articles/${encodeURIComponent(slug)}`,
    ),
};
