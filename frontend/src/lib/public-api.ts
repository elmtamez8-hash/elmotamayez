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
  published_at: string;
  updated_at: string;
  category?: ArticleTaxonomy | null;
  tags?: ArticleTaxonomy[];
};

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
