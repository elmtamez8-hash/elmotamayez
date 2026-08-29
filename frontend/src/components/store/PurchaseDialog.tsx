"use client";

import { useState } from "react";
import { EMPTY_ADDRESS, ShippingAddressFields, type ShippingAddress } from "./ShippingAddressFields";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { store, type StoreItem, type StorePurchase } from "@/lib/store";

/**
 * Confirm a purchase.
 *
 * ⚠️ NOT A MODAL. There is no dialog in `components/ui/`, and inventing one means
 * a focus trap, a scroll lock and an escape handler for a two-field form —
 * `ConfirmButton` made the same trade for the same reason. This is a card that
 * replaces the grid, so the back button and the keyboard both behave.
 *
 * ⚠️ AND THE ADDRESS APPEARS FOR A PRINTED ITEM ONLY. A screen that asked a
 * buyer for a postal address to download a file is the `LessonEditor` defect:
 * offering an action that depends on a type the screen has not read.
 *
 * ⚠️ THE IDEMPOTENCY KEY IS MINTED PER ATTEMPT and sent with the request. Without
 * it `Idempotent` middleware returns early and a double tap on a slow connection
 * writes two orders, each waiting for its own bank transfer.
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
  const [address, setAddress] = useState<ShippingAddress>(EMPTY_ADDRESS);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const printed = item.kind === "physical";
  const count = Math.max(1, Number.parseInt(quantity, 10) || 1);
  const goods = item.price_minor * count;
  const shipping = printed ? (item.shipping_fee_minor ?? 0) : 0;

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

        <Alert tone="info" title={`الإجمالي: ${formatMinorMoney(goods + shipping, item.currency)}`}>
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
