"use client";

import { useCallback, useEffect, useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField, TextareaField, SelectField } from "@/components/ui/Field";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { blog, type ArticleInput, type ManagedArticle } from "@/lib/blog";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { P, can } from "@/lib/permissions";

/**
 * مدوّنةُ المدرّس — قائمةُ مقالاتِه ومحرّرُها.
 *
 * ⛔ **القدرةُ كانت موجودةً والبابُ لم يكن.** `cms.create` و`cms.update` و
 * `cms.delete` و`cms.publish` في دورَي المدرّسِ والمساعدِ منذُ ٠١١، وخلفَها
 * موديلٌ وسياسةٌ وتوليدُ رابطٍ عربيٍّ وإعلانُ IndexNow وخريطةُ موقعٍ ومدوّنةٌ
 * عامّةٌ كلُّها مبنيّةٌ حولَ ما يكتبُه المدرّس — ولا شاشةَ واحدةَ في المنتَجِ
 * تكتبُ مقالاً. قِيسَ في ٢٠٢٦-٠٩-٠٥: `/admin` لا تقبلُ دوراً في مساحةِ عمل، ولا
 * `cms.*` في صلاحيّاتِ المنصّة، فمديرُ المنصّةِ وحدَه كان يكتب.
 *
 * ⚠️ **وحقلا «الحالة» و«تاريخ النشر» يغيبانِ عمّن لا يملكُ `cms.publish` ولا
 * يُعطَّلان**؟ لا — بل يُعطَّلانِ ويُقرآن. عكسُ زرِّ إلغاءِ الاشتراك، وللسببِ
 * نفسِه مقلوباً: المساعدُ الذي يكتبُ ويحرّرُ يحتاجُ أن **يرى** أمنشورٌ ما كتبَه
 * أم لا — إخفاءُ الحقلِ يجعلُ «لماذا لا تظهرُ مسوّدتي على المدوّنة؟» سؤالاً لا
 * شيءَ على الشاشةِ يجيبُه. والخادمُ يرفضُ التحريكَ في الحالتَين.
 */

const EMPTY: ArticleInput = {
  title: "",
  excerpt: "",
  body: "",
  slug: "",
  status: "draft",
  published_at: "",
  seo_title: "",
  seo_description: "",
  canonical_url: "",
};

/**
 * ⚠️ `datetime-local` يريدُ `YYYY-MM-DDTHH:mm` ولا يقبلُ ISO الكاملَ بحرفِ `Z`،
 * فيبقى الحقلُ فارغاً بلا خطأ — ويقرأُ المدرّسُ ذلك «لا تاريخَ لمقالي المنشور».
 */
function toLocalInput(iso: string | null): string {
  if (!iso) return "";
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}T${pad(at.getHours())}:${pad(at.getMinutes())}`;
}

function formOf(article: ManagedArticle): ArticleInput {
  return {
    title: article.title,
    excerpt: article.excerpt ?? "",
    body: article.body ?? "",
    slug: article.slug ?? "",
    status: article.status,
    published_at: toLocalInput(article.published_at),
    seo_title: article.seo_title ?? "",
    seo_description: article.seo_description ?? "",
    canonical_url: article.canonical_url ?? "",
  };
}

export default function ManageBlogPage() {
  const { user } = useAuth();
  const mayPublish = can(user, P.cmsPublish);
  const mayDelete = can(user, P.cmsDelete);
  const mayCreate = can(user, P.cmsCreate);

  const [rows, setRows] = useState<ManagedArticle[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [editing, setEditing] = useState<ManagedArticle | "new" | null>(null);
  const [form, setForm] = useState<ArticleInput>(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    try {
      const res = await blog.list();
      setRows(res.data);
      setState("ready");
    } catch {
      setState("error");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function open(article: ManagedArticle | "new") {
    setEditing(article);
    setForm(article === "new" ? EMPTY : formOf(article));
    setError(null);
    setFields({});
  }

  async function save() {
    setBusy(true);
    setError(null);
    setFields({});

    /*
     * ⚠️ الفارغُ يُرسَلُ `null` لا `""`. القواعدُ `nullable|url` و`nullable|date`،
     * والسلسلةُ الفارغةُ ليست تاريخاً ولا رابطاً — فتردُّ ٤٢٢ عن حقلٍ لم يلمسْه
     * المدرّسُ أصلاً.
     */
    const payload: ArticleInput = {
      title: form.title,
      body: form.body || null,
      excerpt: form.excerpt || null,
      slug: form.slug || null,
      status: form.status,
      published_at: form.published_at ? new Date(form.published_at).toISOString() : null,
      seo_title: form.seo_title || null,
      seo_description: form.seo_description || null,
      canonical_url: form.canonical_url || null,
    };

    try {
      if (editing === "new") {
        await blog.create(payload);
      } else if (editing) {
        await blog.update(editing.uuid, payload);
      }
      setEditing(null);
      // ⚠️ يُعادُ التحميلُ من الخادمِ ولا يُحدَّثُ الصفُّ محليّاً: الرابطُ يُولَّدُ
      // هناك من العنوانِ العربيِّ ويُحَلُّ تصادمُه بـ`-2`، وتاريخُ النشرِ قد
      // يُختَمُ تلقائيّاً. الرقمُ المكتوبُ ليس الرقمَ المحفوظ.
      await load();
    } catch (err) {
      setFields(fieldErrors(err));
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }

  async function remove(article: ManagedArticle) {
    setBusy(true);
    setError(null);
    try {
      await blog.remove(article.uuid);
      setEditing(null);
      await load();
    } catch (err) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }

  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">المدوّنة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          ما تكتبه هنا يظهر على صفحتك العامّة في «المدوّنة»، وتُخبَر محرّكات البحث به لحظة
          نشره. المسوّدة لا يراها أحد غيرك.
        </p>
      </header>

      {editing === null && mayCreate ? (
        <div>
          <Button onClick={() => open("new")}>مقال جديد</Button>
        </div>
      ) : null}

      {editing !== null ? (
        <Card>
          <h2 className="mb-4 font-semibold text-ink">
            {editing === "new" ? "مقال جديد" : "تعديل المقال"}
          </h2>

          {error ? <Alert tone="danger" title={error} /> : null}

          <div className="space-y-4">
            <TextField
              id="article-title"
              label="العنوان"
              required
              value={form.title}
              onChange={(v) => setForm({ ...form, title: v })}
              error={fields.title}
            />

            <TextField
              id="article-slug"
              label="الرابط"
              value={form.slug ?? ""}
              onChange={(v) => setForm({ ...form, slug: v })}
              error={fields.slug}
              hint="اتركه فارغاً ليُبنى من العنوان. لا يتغيّر بعد ذلك مع تغيّر العنوان — الرابط المنشور الذي يتبدّل رابط مكسور."
            />

            <TextareaField
              id="article-excerpt"
              label="المقتطف"
              value={form.excerpt ?? ""}
              onChange={(v) => setForm({ ...form, excerpt: v })}
              error={fields.excerpt}
              hint="السطران اللذان يظهران في قائمة المدوّنة وفي نتيجة البحث."
            />

            <TextareaField
              id="article-body"
              label="النصّ"
              rows={12}
              value={form.body ?? ""}
              onChange={(v) => setForm({ ...form, body: v })}
              error={fields.body}
              hint="يُكتب بصيغة Markdown. الوسم الخام يُزال عند العرض، فلا تضع HTML."
            />

            <SelectField
              id="article-status"
              label="الحالة"
              value={form.status ?? "draft"}
              onChange={(v) => setForm({ ...form, status: v as ArticleInput["status"] })}
              disabled={!mayPublish}
              options={[
                { value: "draft", label: "مسوّدة" },
                { value: "published", label: "منشور" },
              ]}
              
              error={fields.status}
              hint={mayPublish ? undefined : "النشر والسحب لمن يحمل صلاحية النشر."}
            />

            <TextField
              id="article-published-at"
              label="تاريخ النشر"
              type="datetime-local"
              value={form.published_at ?? ""}
              onChange={(v) => setForm({ ...form, published_at: v })}
              disabled={!mayPublish}
              
              error={fields.published_at}
              hint="تاريخ في المستقبل يُبقي المقال خارج المدوّنة حتّى يحين."
            />

            <TextField
              id="article-seo-title"
              label="عنوان محرّكات البحث"
              value={form.seo_title ?? ""}
              onChange={(v) => setForm({ ...form, seo_title: v })}
              error={fields.seo_title}
            />

            <TextareaField
              id="article-seo-description"
              label="وصف محرّكات البحث"
              value={form.seo_description ?? ""}
              onChange={(v) => setForm({ ...form, seo_description: v })}
              error={fields.seo_description}
            />

            <TextField
              id="article-canonical"
              label="الرابط الأساسي"
              value={form.canonical_url ?? ""}
              onChange={(v) => setForm({ ...form, canonical_url: v })}
              error={fields.canonical_url}
              hint="اتركه فارغاً إلّا إن كان المقال منشوراً في مكان آخر أصلاً."
            />
          </div>

          <div className="mt-6 flex flex-wrap gap-2">
            <Button onClick={save} disabled={busy || !form.title}>
              احفظ
            </Button>
            <Button variant="ghost" onClick={() => setEditing(null)} disabled={busy}>
              إلغاء
            </Button>
            {editing !== "new" && mayDelete ? (
              <Button variant="danger" onClick={() => remove(editing)} disabled={busy}>
                احذف
              </Button>
            ) : null}
          </div>
        </Card>
      ) : null}

      {state === "loading" ? (
        <RowsSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          title="لا مقالات بعد"
          description="أوّل مقال تنشره يظهر على صفحتك العامّة، وتُخبَر محرّكات البحث به."
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((article) => (
            <li key={article.uuid}>
              <Card as="article">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="font-semibold text-ink">{article.title}</h3>
                    <p className="mt-1 text-sm text-ink-muted">
                      {article.status === "published" && article.published_at
                        ? formatDate(article.published_at)
                        : "لم يُنشر بعد"}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={article.status === "published" ? "success" : "neutral"}>
                      {article.status === "published" ? "منشور" : "مسوّدة"}
                    </Badge>
                    <Button variant="ghost" onClick={() => open(article)}>
                      عدّل
                    </Button>
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
