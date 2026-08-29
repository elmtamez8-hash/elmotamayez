"use client";

import { useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import {
  CheckboxField,
  NumberField,
  SelectField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import {
  store,
  teacherNetMinor,
  type StoreItem,
  type StoreItemInput,
  type StoreItemKind,
} from "@/lib/store";

/**
 * Create or edit a product.
 *
 * ⚠️ THE FIELDS SWITCH ON `kind`, AND THAT IS THE WHOLE DESIGN. A screen that
 * offers a stock count for a file, or asks for a video on a printed book, is the
 * `LessonEditor` defect reached from a new direction: a screen that does not
 * know an item's type must not offer an action that depends on it. The server
 * refuses either mismatch, so the alternative is a correct refusal to a request
 * the screen invented.
 *
 * ⚠️ AND THE TEACHER'S SHARE IS COMPUTED HERE, from the price they typed and the
 * published rate. The API does not send a net — it cannot, because a number on
 * this screen plus a number on the buyer's screen is the platform's margin. The
 * arithmetic mirrors `StoreSettings::commissionOn()` including the floor; a
 * different rounding here shows a riyal that never arrives.
 */
export function StoreItemForm({
  item,
  commissionBps,
  onSaved,
  onCancel,
}: {
  item?: StoreItem;
  /** Read off any existing product, or the platform default until one exists. */
  commissionBps: number;
  onSaved: (saved: StoreItem) => void;
  onCancel: () => void;
}) {
  const [kind, setKind] = useState<StoreItemKind>(item?.kind ?? "digital");
  const [title, setTitle] = useState(item?.title ?? "");
  const [excerpt, setExcerpt] = useState(item?.excerpt ?? "");
  const [description, setDescription] = useState(item?.description ?? "");
  const [price, setPrice] = useState(String(item?.price_minor ?? ""));
  const [stock, setStock] = useState(item?.stock === null ? "" : String(item?.stock ?? ""));
  const [shipping, setShipping] = useState(
    item?.shipping_fee_minor === null ? "" : String(item?.shipping_fee_minor ?? ""),
  );
  /*
   * ⚠️ ALWAYS EMPTY, AND THE SERVER IS WHAT MAKES THAT SAFE.
   * `StoreItemResource` deliberately does not send `media_asset_uuid` — the same
   * payload reaches a buyer, and a raw asset identifier there is the leak FR-011
   * forbids — so there is nothing to prefill. `SaveStoreItem` keeps the existing
   * file when the field arrives null, which is what lets a teacher fix a typo in
   * a title without re-typing a uuid they cannot see.
   */
  const [assetUuid, setAssetUuid] = useState("");
  const [isActive, setIsActive] = useState(item?.is_active ?? true);

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const priceMinor = Number.parseInt(price, 10);
  const netMinor = Number.isFinite(priceMinor) ? teacherNetMinor(priceMinor, commissionBps) : null;
  const currency = item?.currency ?? "QAR";

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setProblem(null);

    const body: StoreItemInput = {
      kind,
      title,
      price_minor: priceMinor,
      description: description || null,
      excerpt: excerpt || null,
      is_active: isActive,
      // ⚠️ SENT AS `null` FOR THE OTHER TYPE, NOT OMITTED AND NOT ZERO. `null` is
      // what «cannot run out» means; a zero would make every digital item read
      // as sold out for ever.
      stock: kind === "physical" ? Number.parseInt(stock, 10) : null,
      shipping_fee_minor: kind === "physical" ? Number.parseInt(shipping, 10) : null,
      media_asset_uuid: kind === "digital" ? assetUuid || null : null,
    };

    try {
      const res = item ? await store.updateItem(item.uuid, body) : await store.createItem(body);
      onSaved(res.data);
    } catch (error) {
      // 422 lands under its field; everything else becomes one Arabic sentence.
      // A raw error never reaches the teacher.
      const fields = fieldErrors(error);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else {
        setProblem(userMessage(error));
      }
    } finally {
      setSaving(false);
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

      <TextField
        id="title"
        label="العنوان"
        value={title}
        onChange={setTitle}
        error={errors.title}
        required
      />

      <TextField
        id="excerpt"
        label="المقتطف"
        hint="سطر واحد يظهر في القائمة. اتركه فارغاً ليظهر أول الوصف."
        value={excerpt}
        onChange={setExcerpt}
        error={errors.excerpt}
      />

      <TextareaField
        id="description"
        label="الوصف"
        value={description}
        onChange={setDescription}
        error={errors.description}
      />

      <NumberField
        id="price_minor"
        label="سعر البيع"
        hint="بالدرهم — أصغر وحدة من العملة."
        value={price}
        onChange={setPrice}
        min={1}
        error={errors.price_minor}
        required
      />

      {netMinor !== null && priceMinor >= 1 && (
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
            id="shipping_fee_minor"
            label="رسم الشحن"
            value={shipping}
            onChange={setShipping}
            min={0}
            error={errors.shipping_fee_minor}
            required
          />
        </>
      ) : (
        <TextField
          id="media_asset_uuid"
          label="الملف المرفق"
          hint={
            item
              ? "اتركه فارغاً للإبقاء على الملف الحالي، أو اكتب معرّف ملف آخر من مساحتك."
              : "معرّف ملف رفعتَه في مساحتك. الملفات المرفوعة عند مدرّس آخر لا تُقبل."
          }
          value={assetUuid}
          onChange={setAssetUuid}
          error={errors.media_asset_uuid}
          required={!item}
        />
      )}

      <CheckboxField
        id="is_active"
        label="معروض للبيع"
        checked={isActive}
        onChange={setIsActive}
      />

      <div className="flex gap-2">
        <Button type="submit" disabled={saving}>
          {saving ? "جارٍ الحفظ…" : "حفظ"}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          إلغاء
        </Button>
      </div>
    </form>
  );
}
