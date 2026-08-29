import { api } from "./api";

/**
 * The teacher's store: books and notes, digital or printed (spec 011 · US1).
 *
 * The types below mirror `StoreItemResource`, `StoreOrderResource` and
 * `ShipmentResource` field for field. Read the PHP resource before changing one:
 * a type that claims a field the API does not send renders a blank with no error
 * anywhere, which is how `/enrollments` spent four phases showing «كورس رقم »
 * and nothing after it.
 *
 * ⚠️ NO `teacher_net_minor` AND NO `amount_minor` ANYWHERE IN THIS FILE. The
 * server does not send either — the teacher's share is derived in the browser
 * from the price and the published rate, and the buyer's money key is
 * `total_minor`. A field added here that the API does not have is a field
 * somebody will later "fix" by adding it to the Resource, which fails the build
 * in `ContextIsolationTest`.
 */

export type StoreItemKind = "digital" | "physical";

export type ShipmentStatus = "pending" | "packed" | "shipped" | "delivered" | "returned";

export interface StoreItem {
  uuid: string;
  kind: StoreItemKind;
  kind_label: string;
  title: string;
  excerpt: string | null;
  description: string | null;
  price_minor: number;
  currency: string;
  shipping_fee_minor: number | null;
  /**
   * ⚠️ `null` MEANS «CANNOT RUN OUT», NOT ZERO. A digital item has no stock, and
   * a screen that renders `stock ?? 0` tells every buyer the file is sold out.
   */
  stock: number | null;
  is_active: boolean;
  /** Basis points. 1000 = 10%. Integers all the way, never a float. */
  commission_bps: number;
  course?: { uuid: string | null; title: string | null };
  created_at: string | null;
}

export interface Shipment {
  uuid: string;
  status: ShipmentStatus;
  status_label: string;
  /**
   * ⚠️ SENT BY THE SERVER, NEVER DERIVED HERE. The ladder is a list rather than
   * an order — `returned` follows a delivery attempt and nothing else — and a
   * client that guessed it would offer «مُرتجَع» after «وصل». Two spellings of
   * one rule put one answer on the screen and another at the door.
   */
  next_statuses: Array<{ value: ShipmentStatus; label: string }>;
  tracking_ref: string | null;
  status_changed_at: string | null;
  /** Present only for a reader holding `store.shipments.manage`. */
  recipient_name?: string;
  phone?: string;
  address_line?: string;
  notes?: string | null;
}

export interface StorePurchase {
  uuid: string;
  quantity: number;
  unit_price_minor: number;
  discount_minor: number;
  shipping_minor: number;
  /**
   * ⚠️ WHAT THE BUYER MUST TRANSFER, POSTAGE INCLUDED. It omitted the shipping
   * once: a printed purchase showed 50 while the order was waiting for 65, so the
   * buyer sent what this screen told them and the payment callback answered
   * `mismatch` — no delivery, no refund, and a reconciliation case opened over
   * our own arithmetic.
   */
  total_minor: number;
  currency: string;
  is_fulfilled: boolean;
  opened_at: string | null;
  refunded_at: string | null;
  /**
   * ⚠️ READ, NEVER RECOMPUTED. The two conditions are a clock and a flag, and a
   * button enabled by a client-side guess is a button the server then refuses.
   */
  is_refundable: boolean;
  purchased_at: string | null;
  item?: StoreItem;
  shipment?: Shipment;
}

type Paginated<T> = { data: T[]; meta?: { total: number; current_page: number; last_page: number } };

/**
 * The shape a product form submits, named so `updateItem` can reuse it.
 * `Parameters<typeof store.createItem>[0]` would make `store` reference itself
 * inside its own initialiser — TS7022, and the whole object silently becomes
 * `any`.
 */
export interface StoreItemInput {
  kind: StoreItemKind;
  title: string;
  price_minor: number;
  description?: string | null;
  excerpt?: string | null;
  course_uuid?: string | null;
  media_asset_uuid?: string | null;
  stock?: number | null;
  shipping_fee_minor?: number | null;
  is_active?: boolean;
}

/**
 * What the teacher keeps, in minor units.
 *
 * Computed in the browser from the two numbers the server sends, because sending
 * the net itself would put the platform's cut one subtraction away from anybody
 * who can open the network tab — including the student.
 *
 * Floors, matching `StoreSettings::commissionOn()`. A different rounding here
 * shows the teacher a riyal they will not receive.
 */
export function teacherNetMinor(priceMinor: number, commissionBps: number): number {
  return priceMinor - Math.floor((priceMinor * commissionBps) / 10_000);
}

export const store = {
  // ── The teacher ──────────────────────────────────────────────────────────
  items: () => api.get<Paginated<StoreItem>>("/store/items"),

  createItem: (body: StoreItemInput) => api.post<{ data: StoreItem }>("/store/items", body),

  updateItem: (uuid: string, body: StoreItemInput) =>
    api.put<{ data: StoreItem }>(`/store/items/${uuid}`, body),

  shipments: () =>
    api.get<Paginated<Shipment> & { meta?: { statuses?: Record<string, string> } }>("/store/shipments"),

  advanceShipment: (uuid: string, body: { status: ShipmentStatus; tracking_ref?: string | null }) =>
    api.patch<{ data: Shipment }>(`/store/shipments/${uuid}`, body),

  // ── The buyer ────────────────────────────────────────────────────────────
  catalogue: (workspaceUuid: string) =>
    api.get<Paginated<StoreItem>>(`/store/catalogue?workspace_uuid=${encodeURIComponent(workspaceUuid)}`),

  purchases: () => api.get<Paginated<StorePurchase>>("/store/purchases"),

  /**
   * ⚠️ THE IDEMPOTENCY KEY IS THE CLIENT'S JOB, AND WITHOUT IT THE MIDDLEWARE
   * IS A NO-OP. `Idempotent::handle()` returns early when the header is absent
   * — so a double-tapped buy button on a slow connection writes two orders,
   * each waiting for its own bank transfer, and the route's `idempotent` reads
   * as a guard that is not there. Generated here rather than passed in: the key
   * belongs to one attempt, and a caller holding it across retries of a
   * DIFFERENT purchase would replay the wrong order.
   */
  buy: (
    body: {
      item_uuid: string;
      quantity?: number;
      recipient_name?: string;
      phone?: string;
      address_line?: string;
      notes?: string;
    },
    idempotencyKey: string,
  ) =>
    api.postIdempotent<{ data: StorePurchase }>("/store/purchases", body, idempotencyKey),

  /**
   * ⚠️ OPENING IS IRREVERSIBLE FOR THE REFUND. The screen must say so BEFORE the
   * press, not after — the server stamps `first_accessed_at` on the way in.
   */
  open: (uuid: string) => api.post<{ grant_uuid: string }>(`/store/purchases/${uuid}/open`, {}),

  refund: (uuid: string, idempotencyKey: string) =>
    api.postIdempotent<{ data: StorePurchase }>(
      `/store/purchases/${uuid}/refund`,
      {},
      idempotencyKey,
    ),

  /** One key per attempt. `crypto.randomUUID` is available in every browser this product supports. */
  newIdempotencyKey: (): string => crypto.randomUUID(),
};
