"use client";

import { useEffect, useState } from "react";
import { CouponField } from "./CouponField";
import { EMPTY_ADDRESS, ShippingAddressFields, type ShippingAddress } from "./ShippingAddressFields";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { store, type AppliedDiscount, type StoreItem, type StorePurchase } from "@/lib/store";

/**
 * Confirm a purchase.
 *
 * ⚠️ NOT A MODAL, AND STILL NOT ONE NOW THAT `components/ui/` HAS `Modal`
 * (spec 033). The reason changed: it was the cost of building one, and it is now
 * that a purchase is a FORM with a coupon field, an address and a server-side
 * discount preview — several steps and several refusals, one of which sends the
 * buyer back to fix an address. A card that replaces the grid keeps the back
 * button and the keyboard behaving; a window would put a multi-step flow inside
 * something whose Escape key throws the whole thing away.
 *
 * ⚠️ AND THE ADDRESS APPEARS FOR A PRINTED ITEM ONLY. A screen that asked a
 * buyer for a postal address to download a file is the `LessonEditor` defect:
 * offering an action that depends on a type the screen has not read.
 *
 * ⚠️ THE IDEMPOTENCY KEY IS MINTED PER ATTEMPT and sent with the request. Without
 * it `Idempotent` middleware returns early and a double tap on a slow connection
 * writes two orders, each waiting for its own bank transfer.
 *
 * ⚠️ AND THE DISCOUNT IS ASKED FOR ONCE ON OPEN, WITH NO CODE (FR-011). That is
 * the only way a family discount reaches the screen: it is automatic, so a buyer
 * who types nothing would otherwise be shown a total the server is about to
 * disagree with — and «the discount appears explicitly before payment» is the
 * requirement, not «the coupon does».
 *
 * ⚠️ THE CODE ITSELF IS SENT TO THE SERVER, NEVER THE AMOUNT. The preview claims
 * nothing and holds nothing; the purchase re-resolves the discount against the
 * real line, so a client that posted its own figure would be posting a number
 * the server has to ignore anyway.
 */
export function PurchaseDialog({
  item,
  onDone,
  onCancel,
}: {
  item: StoreItem;
  onDone: (purchase: StorePurchase) => void;
  onCancel: () => void;
}) {
  const [quantity, setQuantity] = useState("1");
  const [discount, setDiscount] = useState<AppliedDiscount | null>(null);
  const [code, setCode] = useState<string | null>(null);
  const [address, setAddress] = useState<ShippingAddress>(EMPTY_ADDRESS);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const printed = item.kind === "physical";
  const count = Math.max(1, Number.parseInt(quantity, 10) || 1);
  const goods = item.price_minor * count;
  const shipping = printed ? (item.shipping_fee_minor ?? 0) : 0;
  // The postage is never discounted: it is money the teacher hands to a courier,
  // and the server splits it the same way.
  const off = Math.min(discount?.discount_minor ?? 0, goods);

  useEffect(() => {
    let live = true;

    store
      .previewDiscount({ kind: "store_item", uuid: item.uuid })
      // A preview that fails costs the buyer nothing — the server applies what
      // is due at purchase either way — so it is swallowed rather than turned
      // into an error over a line that is merely informative.
      .then((applied) => {
        if (live && applied.discount_minor > 0) setDiscount(applied);
      })
      .catch(() => undefined);

    return () => {
      live = false;
    };
  }, [item.uuid]);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setProblem(null);

    try {
      const res = await store.buy(
        {
          item_uuid: item.uuid,
          quantity: count,
          ...(code !== null ? { coupon_code: code } : {}),
          ...(printed
            ? {
                recipient_name: address.recipient_name,
                phone: address.phone,
                address_line: address.address_line,
                notes: address.notes || undefined,
              }
            : {}),
        },
        store.newIdempotencyKey(),
      );

      onDone(res.data);
    } catch (error) {
      const fields = fieldErrors(error);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else {
        setProblem(userMessage(error));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <form onSubmit={submit} className="space-y-4">
        <header>
          <h2 className="text-lg font-semibold text-ink">{item.title}</h2>
          <p className="mt-1 text-sm text-ink-muted">{item.kind_label}</p>
        </header>

        {problem && <Alert tone="danger" title={problem} />}

        {printed && (
          <NumberField
            id="quantity"
            label="الكمية"
            value={quantity}
            onChange={setQuantity}
            min={1}
            error={errors.quantity}
          />
        )}

        {printed && (
          <ShippingAddressFields value={address} onChange={setAddress} errors={errors} />
        )}

        <CouponField
          kind="store_item"
          uuid={item.uuid}
          currency={item.currency}
          onApplied={(applied, appliedCode) => {
            setDiscount(applied);
            setCode(appliedCode);
          }}
        />

        {off > 0 && (
          <p className="text-sm text-ink-muted">
            قبل الخصم: {formatMinorMoney(goods + shipping, item.currency)} · الخصم:{" "}
            {formatMinorMoney(off, item.currency)}
          </p>
        )}

        <Alert tone="info" title={`الإجمالي: ${formatMinorMoney(goods - off + shipping, item.currency)}`}>
          {printed
            ? "يشمل رسم الشحن. يُخصم المخزون ويبدأ التجهيز فور اعتماد دفعتك."
            : "يُفتح الملف فور اعتماد دفعتك. الدفع تحويل بنكي يدوي، فقد يستغرق الاعتماد يوماً أو أكثر."}
        </Alert>

        <div className="flex gap-2">
          <Button type="submit" disabled={busy}>
            {busy ? "جارٍ الإرسال…" : "تأكيد الطلب"}
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            رجوع
          </Button>
        </div>
      </form>
    </Card>
  );
}
