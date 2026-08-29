"use client";

import { TextField, TextareaField } from "@/components/ui/Field";

export interface ShippingAddress {
  recipient_name: string;
  phone: string;
  address_line: string;
  notes: string;
}

export const EMPTY_ADDRESS: ShippingAddress = {
  recipient_name: "",
  phone: "",
  address_line: "",
  notes: "",
};

/**
 * Where a printed copy is going.
 *
 * ⚠️ ASKED BEFORE THE MONEY, NOT AT FULFILMENT. FR-007 refuses the purchase
 * rather than taking payment and discovering days later that there is nowhere to
 * post the parcel — by which time the person who could answer has left the
 * screen.
 *
 * The address is copied onto the shipment as a SNAPSHOT: moving house in
 * November must not rewrite the destination of a parcel posted in September.
 */
export function ShippingAddressFields({
  value,
  onChange,
  errors,
}: {
  value: ShippingAddress;
  onChange: (next: ShippingAddress) => void;
  errors: Record<string, string>;
}) {
  function set<K extends keyof ShippingAddress>(key: K, next: string) {
    onChange({ ...value, [key]: next });
  }

  return (
    <div className="space-y-4">
      <TextField
        id="recipient_name"
        label="اسم المستلم"
        value={value.recipient_name}
        onChange={(next) => set("recipient_name", next)}
        error={errors.recipient_name}
        required
      />

      {/*
        A plain field rather than `PhoneInput`. That component splits a dial code
        from a number for a phone the PLATFORM will verify and message; this one
        is a courier's contact detail copied onto a parcel, never normalised and
        never dispatched to. Reusing it here would demand a country picker for a
        number nobody on this platform will ever call.
      */}
      <TextField
        id="phone"
        label="رقم الهاتف"
        hint="رقم يصل منه مندوب التوصيل إليك."
        value={value.phone}
        onChange={(next) => set("phone", next)}
        error={errors.phone}
        required
      />

      <TextField
        id="address_line"
        label="العنوان"
        hint="المدينة والمنطقة والشارع ورقم المبنى."
        value={value.address_line}
        onChange={(next) => set("address_line", next)}
        error={errors.address_line}
        required
      />

      <TextareaField
        id="notes"
        label="ملاحظات"
        hint="اختياري — أي تفصيل يساعد على الوصول."
        value={value.notes}
        onChange={(next) => set("notes", next)}
        error={errors.notes}
      />
    </div>
  );
}
