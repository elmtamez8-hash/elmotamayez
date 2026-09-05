import { api } from "@/lib/api";

/**
 * المدوّنةُ من جهةِ المدرّس — قراءةُ مقالاتِه وكتابتُها.
 *
 * ⚠️ **`article.ts` جارُ هذا الملفِّ ولا يُغنيه**: ذاك يشتقُّ من نصِّ المقالِ ما
 * يعرضُه القارئُ (زمنُ القراءة، فهرسُ العناوين) وهذا يتكلّمُ مع المسارات. فصلُهما
 * مقصود: صفحةُ المدوّنةِ العامّةُ تستوردُ الأوّلَ ولا تحتاجُ إلى نداءٍ مصادَقٍ عليه.
 */

/** حالةُ المقال. لا ثالثَ لهما في الخلفيّة (`in:draft,published`). */
export type ArticleStatus = "draft" | "published";

export interface ManagedArticle {
  uuid: string;
  title: string;
  slug: string;
  excerpt: string | null;
  body: string | null;
  status: ArticleStatus;
  published_at: string | null;
  seo_title: string | null;
  seo_description: string | null;
  canonical_url: string | null;
  created_at: string;
}

export interface ArticleInput {
  title: string;
  body?: string | null;
  excerpt?: string | null;
  slug?: string | null;
  status?: ArticleStatus;
  published_at?: string | null;
  seo_title?: string | null;
  seo_description?: string | null;
  canonical_url?: string | null;
}

export const blog = {
  /**
   * ⚠️ الردُّ مغلَّفٌ بـ`{data, links, meta}` لا مصفوفةً عارية. الخلفيّةُ تُصيِّرُ
   * الصفحةَ بـ`->response()->getData(true)`، وأيُّ قارئٍ يفهرسُ المستوى الأعلى
   * ينكسر.
   */
  list: () => api.get<{ data: ManagedArticle[] }>("/manage/articles"),

  create: (input: ArticleInput) => api.post<{ data: ManagedArticle }>("/manage/articles", input),

  update: (uuid: string, input: ArticleInput) =>
    api.put<{ data: ManagedArticle }>(`/manage/articles/${uuid}`, input),

  remove: (uuid: string) => api.delete<null>(`/manage/articles/${uuid}`),
};
