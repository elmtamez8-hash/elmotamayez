"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { RichMarkdownEditor } from "@/components/ui/RichMarkdownEditor";
import {
  CheckboxField,
  NumberField,
  SelectField,
  TextField,
} from "@/components/ui/Field";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { media } from "@/lib/media";
import {
  majorToMinor,
  store,
  teacherNetMinor,
  type ManagedStoreItem,
  type StoreItemInput,
  type StoreItemKind,
} from "@/lib/store";

const FILE_ACCEPT = ".pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg";

/**
 * Create or edit a product.
 *
 * ⚠️ THE FIELDS SWITCH ON `kind`, AND THAT IS THE WHOLE DESIGN. A screen that
 * offers a stock count for a file, or asks for a file on a printed book, invents
 * a request the server then refuses.
 *
 * ⚠️ A DIGITAL PRODUCT OWNS ITS FILE, SO IT EXISTS BEFORE THE UPLOAD. The server
 * accepts only a file uploaded through `/store/items/{item}/file` and finished
 * (`SaveStoreItem`). A new one is therefore created hidden, given its file, and
 * only then saved with the «معروض للبيع» the teacher chose — all from one press.
 *
 * ⚠️ MONEY IS TYPED IN MAJOR UNITS («150» or «150.50») and sent as typed; the
 * server converts (`MinorUnits`). `majorToMinor` exists only for the share
 * preview, mirroring `StoreSettings::commissionOn()` including the floor.
 */
export function StoreItemForm({
  item,
  commissionBps,
  onSaved,
  onCancel,
}: {
  item?: ManagedStoreItem;
  /** Read off any existing product, or the platform default until one exists. */
  commissionBps: number;
  onSaved: (saved: ManagedStoreItem) => void;
  onCancel: () => void;
}) {
  const [kind, setKind] = useState<StoreItemKind>(item?.kind ?? "digital");
  const [title, setTitle] = useState(item?.title ?? "");
  const [excerpt, setExcerpt] = useState(item?.excerpt ?? "");
  const [description, setDescription] = useState(item?.description ?? "");
  const [price, setPrice] = useState(item?.price ?? "");
  const [stock, setStock] = useState(item?.stock === null ? "" : String(item?.stock ?? ""));
  const [shipping, setShipping] = useState(item?.shipping_fee ?? "");
  const [courseUuid, setCourseUuid] = useState(item?.course?.uuid ?? "");
  const [courses, setCourses] = useState<Array<{ value: string; label: string }>>([]);
  const [subjectSlug, setSubjectSlug] = useState(item?.subject_slug ?? "");
  const [subjects, setSubjects] = useState<Array<{ value: string; label: string }>>([]);
  const [file, setFile] = useState<File | null>(null);
  const [cover, setCover] = useState<File | null>(null);
  const [isActive, setIsActive] = useState(item?.is_active ?? true);

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [step, setStep] = useState<string | null>(null);
  /** The row a first save created, kept for a retry after a failed upload. */
  const [draft, setDraft] = useState<ManagedStoreItem | null>(null);

  useEffect(() => {
    // The same call the plans and announcements screens make; the server's
    // assistant scope narrows it, and a failure leaves «بدون كورس» only.
    api
      .get<{ data: Array<{ uuid: string; title: string }> }>("/courses?per_page=200")
      .then((res) => setCourses(res.data.map((course) => ({ value: course.uuid, label: course.title }))))
      .catch(() => setCourses([]));

    // The whole active vocabulary (the signup read), not the marketplace's
    // «subjects with a listed teacher» — a new teacher's first product must be
    // fileable too.
    api
      .get<Array<{ slug: string; name: string }>>("/signup/subjects")
      .then((rows) => setSubjects(rows.map((subject) => ({ value: subject.slug, label: subject.name }))))
      .catch(() => setSubjects([]));
  }, []);

  const priceMinor = majorToMinor(price);
  const netMinor = priceMinor === null ? null : teacherNetMinor(priceMinor, commissionBps);
  const currency = item?.currency ?? "QAR";
  const hasFile = item?.file != null;

  async function uploadFile(target: ManagedStoreItem, chosen: File): Promise<string> {
    setStep("جارٍ رفع الملف… لا تغلق الصفحة.");
    const { asset, upload } = await store.requestFile(target.uuid, { filename: chosen.name, size_bytes: chosen.size });
    await media.uploadTo(upload, chosen);
    const settled = await store.completeFile(target.uuid, asset.uuid);

    // `failed` is an answer, not an error: a type the server refused, say so
    // here rather than as «الملف المختار غير موجود» from the save after it.
    if (settled.status !== "ready") {
      throw new Error("store-file-not-ready");
    }

    return asset.uuid;
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setErrors({});
    setProblem(null);

    const body: StoreItemInput = {
      kind,
      title,
      price,
      description: description || null,
      excerpt: excerpt || null,
      course_uuid: courseUuid || null,
      subject_slug: subjectSlug || null,
      is_active: isActive,
      // `null` for the other type, never zero: `null` is «cannot run out».
      stock: kind === "physical" ? Number.parseInt(stock, 10) : null,
      shipping_fee: kind === "physical" ? shipping : null,
    };

    const needsUpload = kind === "digital" && file !== null;

    // Nothing to sell yet: refused here, under the field, rather than saved
    // hidden behind the teacher's back.
    if (kind === "digital" && !needsUpload && !hasFile && isActive) {
      setErrors({ media_asset_uuid: "اختر ملف المنتج قبل عرضه للبيع." });
      return;
    }

    try {
      setStep("جارٍ الحفظ…");

      /*
       * A NEW product that will get a file now is saved hidden first, and the
       * last save puts it on sale. An existing one keeps selling on its current
       * file while the new one uploads (`SaveStoreItem` keeps the file a save
       * does not name). And a retry after a failed upload reuses the hidden row
       * this form already created — deleting is refused, so a second create is
       * an orphan for ever.
       */
      const existing = item ?? draft;
      const hide = needsUpload && !hasFile;
      let saved = existing
        ? await store.updateItem(existing.uuid, hide ? { ...body, is_active: false } : body)
        : await store.createItem(hide ? { ...body, is_active: false } : body);
      setDraft(saved);

      if (needsUpload && file !== null) {
        const assetUuid = await uploadFile(saved, file);
        setStep("جارٍ الحفظ…");
        saved = await store.updateItem(saved.uuid, { ...body, media_asset_uuid: assetUuid });
      }

      if (cover !== null) {
        setStep("جارٍ رفع صورة الغلاف…");
        saved = await store.uploadCover(saved.uuid, cover);
      }

      onSaved(saved);
    } catch (error) {
      // 422 lands under its field; everything else becomes one Arabic sentence.
      const fields = fieldErrors(error);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else if (error instanceof Error && error.message === "store-file-not-ready") {
        setErrors({ media_asset_uuid: "لم يُقبل الملف. ارفع ملف PDF أو عرضاً تقديمياً سليماً." });
      } else {
        setProblem(userMessage(error));
      }
    } finally {
      setStep(null);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {problem && <Alert tone="danger" title={problem} />}

      <SelectField
        id="kind"
        label="نوع المنتج"
        value={kind}
        onChange={(value) => setKind(value as StoreItemKind)}
        options={[
          { value: "digital", label: "نسخة رقمية" },
          { value: "physical", label: "نسخة مطبوعة" },
        ]}
        error={errors.kind}
        required
      />

      <TextField id="title" label="العنوان" value={title} onChange={setTitle} error={errors.title} required />

      <TextField
        id="excerpt"
        label="المقتطف"
        hint="سطر واحد يظهر في القائمة. اتركه فارغاً ليظهر أول الوصف."
        value={excerpt}
        onChange={setExcerpt}
        error={errors.excerpt}
      />

      {/* Rendered as Markdown on the public product page (`description_html`),
          so it is written in the shared rich-text field. Keyed by the product:
          the editor reads `value` on mount only. */}
      <RichMarkdownEditor
        key={item?.uuid ?? "new"}
        id="description"
        label="الوصف"
        hint="ما يجده الطالب في صفحة المنتج: المحتوى، عدد الصفحات، لمن يناسب."
        value={description}
        onChange={setDescription}
        error={errors.description}
      />

      <SelectField
        id="course_uuid"
        label="الكورس المرتبط (اختياري)"
        value={courseUuid}
        onChange={setCourseUuid}
        options={[{ value: "", label: "بدون كورس" }, ...courses]}
        error={errors.course_uuid}
      />

      <SelectField
        id="subject_slug"
        label="المادة"
        hint="يُصنَّف بها المنتج في المتجر العام. اتركها ليُؤخذ تصنيف الكورس المرتبط."
        value={subjectSlug}
        onChange={setSubjectSlug}
        options={[{ value: "", label: "من الكورس المرتبط" }, ...subjects]}
        error={errors.subject_slug}
      />

      <NumberField
        id="price"
        label="سعر البيع"
        hint="بالعملة كاملة، مثل 150 أو 150.50."
        value={price}
        onChange={setPrice}
        min={0}
        step={0.01}
        error={errors.price}
        required
      />

      {netMinor !== null && netMinor > 0 && (
        <Alert tone="info" title={`نصيبك من كل نسخة: ${formatMinorMoney(netMinor, currency)}`}>
          {`عمولة المنصّة ${(commissionBps / 100).toFixed(1)}% تُقتطع من سعر البيع الذي كتبتَه، ولا تُضاف فوقه.`}
        </Alert>
      )}

      {kind === "physical" ? (
        <>
          <NumberField
            id="stock"
            label="المخزون"
            hint="عدد النسخ المتاحة الآن. يقلّ تلقائياً مع كل عملية بيع."
            value={stock}
            onChange={setStock}
            min={0}
            error={errors.stock}
            required
          />

          <NumberField
            id="shipping_fee"
            label="رسم الشحن"
            value={shipping}
            onChange={setShipping}
            min={0}
            step={0.01}
            error={errors.shipping_fee}
            required
          />
        </>
      ) : (
        <div className="space-y-1">
          <label htmlFor="store-file" className="block text-sm font-medium text-ink">
            ملف المنتج
          </label>
          <input
            id="store-file"
            type="file"
            accept={FILE_ACCEPT}
            disabled={step !== null}
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            className="w-full rounded-xl border border-line bg-surface-raised p-2 text-sm text-ink file:me-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-1.5 file:text-sm file:text-primary-ink disabled:opacity-60"
          />
          <p className="text-xs text-ink-muted">
            {hasFile
              ? `الملف الحالي: ${item?.file?.name ?? "مرفوع"}. اختر ملفاً آخر لاستبداله.`
              : "PDF أو ملف عرض. يُسلَّم للطالب بعد اعتماد دفعته."}
          </p>
          {errors.media_asset_uuid && (
            <p role="alert" className="text-xs font-medium text-danger-ink">
              {errors.media_asset_uuid}
            </p>
          )}
        </div>
      )}

      <div className="space-y-1">
        <label htmlFor="store-cover" className="block text-sm font-medium text-ink">
          صورة الغلاف (اختياري)
        </label>
        {item?.cover_url && (
          // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
          <img src={item.cover_url} alt="" className="h-28 w-20 rounded-lg border border-line object-cover" />
        )}
        <input
          id="store-cover"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={step !== null}
          onChange={(event) => setCover(event.target.files?.[0] ?? null)}
          className="w-full rounded-xl border border-line bg-surface-raised p-2 text-sm text-ink file:me-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-1.5 file:text-sm file:text-primary-ink disabled:opacity-60"
        />
        <p className="text-xs text-ink-muted">صورة طولية تظهر في المتجر وصفحة المنتج.</p>
      </div>

      <CheckboxField id="is_active" label="معروض للبيع" checked={isActive} onChange={setIsActive} />

      {step !== null && (
        <p className="text-xs text-ink-muted" role="status">
          {step}
        </p>
      )}

      <div className="flex gap-2">
        <Button type="submit" disabled={step !== null}>
          {step !== null ? "جارٍ الحفظ…" : "حفظ"}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          إلغاء
        </Button>
      </div>
    </form>
  );
}
