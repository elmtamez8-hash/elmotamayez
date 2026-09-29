"use client";

import { useCallback, useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { RichMarkdownEditor } from "@/components/ui/RichMarkdownEditor";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { DocumentIcon, EditIcon, ListIcon, ScheduleIcon, SearchIcon, SparkIcon } from "@/components/icons";
import { TextField, TextareaField, SelectField } from "@/components/ui/Field";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { blog, type ArticleInput, type ArticleStatus, type ManagedArticle } from "@/lib/blog";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { usePagedList } from "@/lib/use-paged-list";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { counted, formatDate } from "@/lib/labels";
import { P, can } from "@/lib/permissions";
import { FilterBar } from "@/components/ui/FilterBar";
import { RecordList, RecordRow } from "@/components/ui/RecordList";

/** «١٢ مقالاً» — the contract's own example form; not in `NOUNS` yet. */
const POSTS = { one: "مقال واحد", two: "مقالان", few: "مقالات", many: "مقالاً", other: "مقال" };

type StatusFilter = "all" | "published" | "draft";

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

  const [status, setStatus] = useState<StatusFilter>("all");
  const [query, setQuery] = useState("");
  const searched = useDebouncedValue(query);
  const [editing, setEditing] = useState<ManagedArticle | "new" | null>(null);
  const [form, setForm] = useState<ArticleInput>(EMPTY);
  const [busy, setBusy] = useState(false);
  // Deleting takes the article off the public blog with no restore on any
  // screen, so the press asks first.
  const [askingDelete, setAskingDelete] = useState<ManagedArticle | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});

  /*
   * Paged, searched and filtered on the SERVER (15 a page). The fetcher is
   * rebuilt when the settled search or the chip changes, and that restarts the
   * list from page one.
   */
  const fetchPage = useCallback(
    (page: number) =>
      blog.list({ page, q: searched, status: status === "all" ? undefined : status }),
    [searched, status],
  );
  const list = usePagedList<ManagedArticle, Record<ArticleStatus, number>>(fetchPage);
  const { rows, state, total, counts } = list;
  const load = list.reload;

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
      setAskingDelete(null);
    }
  }

  // Every figure here is the server's: `counts` spans the whole search, and
  // «الكل» is their sum — never `rows.length`, which is only what is loaded.
  const drafts = counts?.draft;
  const published = counts?.published;
  const everything = drafts !== undefined && published !== undefined ? drafts + published : undefined;
  const filtering = searched.trim() !== "" || status !== "all";
  const clearFilters = () => {
    setQuery("");
    setStatus("all");
  };

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={DocumentIcon}
        title="المدوّنة"
        description="ما تكتبه هنا يظهر على صفحتك العامّة في «المدوّنة»، وتُخبَر محرّكات البحث به لحظة نشره. المسوّدة لا يراها أحد غيرك."
        actions={
          editing === null && mayCreate ? (
            <Button
              iconStart={<SparkIcon className="h-4 w-4" />}
              onClick={() => open("new")}
            >
              مقال جديد
            </Button>
          ) : undefined
        }
      />

      {editing !== null ? (
        <Card as="section" labelledBy="article-editor">
          <div className="mb-4">
            <SectionHeading
              id="article-editor"
              Icon={editing === "new" ? SparkIcon : EditIcon}
              title={editing === "new" ? "مقال جديد" : "تعديل المقال"}
            />
          </div>

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

            {/*
              The body is still stored as Markdown — the editor reads and writes
              it. `key` remounts the editor for each article: it reads `value`
              once, so switching articles without a remount would keep the last
              one's text on screen.
            */}
            <RichMarkdownEditor
              key={editing === "new" ? "new" : editing.uuid}
              id="article-body"
              label="النصّ"
              value={form.body ?? ""}
              onChange={(v) => setForm((current) => ({ ...current, body: v }))}
              error={fields.body}
              hint="نسّق بأزرار الشريط أو اختصاراتها. العناوين والقوائم والروابط تظهر في المقال المنشور كما تراها هنا."
            />

            {/* Publishing: the state and its date, side by side from 640px. */}
            <div className="grid gap-4 sm:grid-cols-2">
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
            </div>

            {/* Search-engine fields are optional and read last: a hairline and
                their own heading set them apart from what the reader sees. */}
            <div className="space-y-4 border-t border-line pt-4">
              <SectionHeading
                id="article-seo"
                level={4}
                Icon={SearchIcon}
                title="الظهور في محرّكات البحث"
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
          </div>

          <div className="mt-6 flex flex-wrap gap-2">
            <Button onClick={save} disabled={busy || !form.title}>
              احفظ
            </Button>
            <Button variant="ghost" onClick={() => setEditing(null)} disabled={busy}>
              إلغاء
            </Button>
            {editing !== "new" && mayDelete ? (
              <Button variant="danger" onClick={() => setAskingDelete(editing)} disabled={busy}>
                احذف
              </Button>
            ) : null}
          </div>

          <Modal
            open={askingDelete !== null}
            title="حذف المقال"
            message="سيختفي المقال من مدوّنتك العامّة ولا يمكن استرجاعه من هنا."
            confirmLabel="احذف المقال"
            tone="danger"
            busy={busy}
            onConfirm={() => {
              if (askingDelete !== null) void remove(askingDelete);
            }}
            onCancel={() => setAskingDelete(null)}
          />
        </Card>
      ) : null}

      <section aria-labelledby="article-list" className="space-y-4">
        <SectionHeading
          id="article-list"
          Icon={ListIcon}
          title="مقالاتك"
          description={
            everything !== undefined && everything > 0 ? counted(everything, POSTS) : undefined
          }
        />

        {/*
          Search and chips are the SERVER's: the list is paged at 15, so a match
          or a count computed here would describe page one and read as the whole
          blog. The bar stays mounted once anything has loaded — a search that
          empties the list must still be clearable.
        */}
        {list.settled && (rows.length > 0 || filtering) && (
          <FilterBar
            search={{
              id: "article-search",
              label: "ابحث في مقالاتك",
              value: query,
              onChange: setQuery,
              placeholder: "عنوان المقال",
            }}
            filters={{
              label: "حالة المقال",
              value: status,
              onChange: (key) => setStatus(key as StatusFilter),
              options: [
                { key: "all", label: "الكل", count: everything },
                { key: "published", label: "المنشورة", count: published },
                { key: "draft", label: "المسوّدات", count: drafts },
              ],
            }}
            summary={state === "ready" && total !== null ? counted(total, POSTS) : undefined}
          />
        )}

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={() => void load()} />}

        {state === "ready" && rows.length === 0 && !filtering && (
          <EmptyState
            Icon={DocumentIcon}
            title="لا مقالات بعد"
            description="أوّل مقال تنشره يظهر على صفحتك العامّة، وتُخبَر محرّكات البحث به."
            action={
              editing === null && mayCreate ? (
                <Button variant="secondary" iconStart={<SparkIcon />} onClick={() => open("new")}>
                  اكتب أوّل مقال
                </Button>
              ) : undefined
            }
          />
        )}

        {state === "ready" && rows.length === 0 && filtering && (
          <EmptyState
            title="لا مقال يطابق"
            description="لا مقال بهذا البحث أو بهذه الحالة. امسح البحث لترى مقالاتك كلّها."
            action={
              <Button variant="secondary" size="sm" onClick={clearFilters}>
                مسح البحث
              </Button>
            }
          />
        )}

        {state === "ready" && rows.length > 0 && (
          <RecordList labelledBy="article-list">
            {rows.map((article) => {
              const isPublished = article.status === "published";

              return (
                <RecordRow
                  key={article.uuid}
                  level={4}
                  Icon={DocumentIcon}
                  tone={isPublished ? "info" : "neutral"}
                  title={article.title}
                  status={
                    <Badge tone={isPublished ? "success" : "neutral"}>
                      {isPublished ? "منشور" : "مسوّدة"}
                    </Badge>
                  }
                  description={
                    article.excerpt !== null && article.excerpt !== "" ? article.excerpt : undefined
                  }
                  meta={[
                    {
                      key: "date",
                      label: "تاريخ النشر",
                      labelHidden: true,
                      Icon: ScheduleIcon,
                      value:
                        isPublished && article.published_at
                          ? formatDate(article.published_at)
                          : "لم يُنشر بعد",
                    },
                  ]}
                  actions={
                    <Button
                      size="sm"
                      variant="ghost"
                      iconStart={<EditIcon />}
                      onClick={() => open(article)}
                    >
                      عدّل
                    </Button>
                  }
                />
              );
            })}
          </RecordList>
        )}

        {list.moreFailed && <Alert tone="danger" title="تعذّر تحميل المزيد. حاول مرّة أخرى." />}

        {state === "ready" && list.hasMore && (
          <div className="flex justify-center">
            <Button
              variant="secondary"
              loading={list.loadingMore}
              loadingLabel="جارٍ التحميل…"
              onClick={() => void list.loadMore()}
            >
              عرض المزيد
            </Button>
          </div>
        )}
      </section>
    </div>
  );
}
