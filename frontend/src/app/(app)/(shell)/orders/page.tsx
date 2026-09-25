"use client";

import { TransferDestination } from "@/components/billing/TransferDestination";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ComponentType,
  type ReactNode,
} from "react";
import Link from "next/link";
import { api, errorMessage } from "@/lib/api";
import type { Order } from "@/lib/types";
import { TONE_CLASSES, counted, formatDate, formatMinorMoney, statusLabel, statusTone, NOUNS } from "@/lib/labels";
import { planShape, SESSION_TYPE_LABELS } from "@/lib/plans";
import { StatusBadge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/ui/PageHeader";
import { Select } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { arabicNumber } from "@/lib/numerals";
import {
  AlertIcon,
  CheckIcon,
  ClockIcon,
  CloseIcon,
  CoursesIcon,
  CreditsIcon,
  EyeIcon,
  HumanReviewIcon,
  OrdersIcon,
  ScheduleIcon,
  StoreIcon,
  UploadIcon,
  WalletIcon,
} from "@/components/icons";

/*
 * Statuses that still take a receipt.
 *
 * ⚠️ `rejected` IS ON THE LIST (027 · FR-032), AND `approved` IS NOT. A refusal
 * the payer cannot answer is a refusal they repeat — the same transfer, uploaded
 * again as a NEW order, losing the reason, the amount they were quoted and the
 * thread the officer was reading. An approved order is the opposite case: a
 * second image there replaces the document the approver actually read.
 */
const OPEN_STATUSES = ["pending", "under_review", "rejected"];

/*
 * What the row actually bought — «شراء أرصدة» was the whole answer until now.
 *
 * ⚠️ A KIND ALONE IS NOT A DESCRIPTION. Two credit purchases on one course
 * differed by their amount and nothing else, and a subscription said neither its
 * duration nor whether it was a room or a one-to-one — on the screen where a
 * payer checks what they paid for. Both facts were already on the payload: the
 * subscription snapshot since 027, the credit count as of today.
 */
const KIND_LABELS: Record<string, string> = {
  course: "شراء الكورس",
  credits: "شراء أرصدة",
  store: "شراء من المتجر",
  subscription: "اشتراك",
};

/*
 * A mark for the KIND, beside the title that already says it.
 *
 * Decorative on purpose and with no `title`: the row spells what it bought one
 * character to the side, and announcing the glyph again is noise to a screen
 * reader. What it buys a sighted reader is a list they can scan by shape —
 * «where is the subscription among nine credit purchases» stops being a read.
 */
const KIND_ICONS: Record<string, ComponentType<{ className?: string }>> = {
  course: CoursesIcon,
  credits: CreditsIcon,
  store: StoreIcon,
  subscription: ScheduleIcon,
};

/*
 * The strip above the table: the summary AND the filter, deliberately one control.
 *
 * ⚠️ A COUNT THE READER CANNOT ACT ON IS DECORATION, and a filter with no count
 * is a guess about whether pressing it shows anything. Two rows of chrome
 * answering half a question each is what this replaces.
 *
 * ⚠️ THE LABELS COME FROM `statusLabel()` AND ARE NOT SPELLED AGAIN HERE. A
 * second «قيد المراجعة» written in this file drifts from the badge in the row
 * directly beneath it, which is the two-spellings defect wearing its smallest
 * possible face.
 *
 * ⛔ AND THE TILES ARE DERIVED FROM THE ROWS, NOT WRITTEN OUT. A hand-written
 * list of four had already missed one: `OrderStatus` carries `cancelled` as a
 * fifth case, so «الكل: ٧» would have sat above tiles adding to six, with the
 * seventh row visible in the table and no tile able to reach it. This ordering
 * is presentation only — a status that is not on it still gets a tile, at the
 * end — so a sixth case added tomorrow appears by itself.
 */
const STATUS_ORDER = ["pending", "under_review", "rejected", "approved", "refund_due", "cancelled"];

const STATUS_ICONS: Record<string, ComponentType<{ className?: string }>> = {
  pending: ClockIcon,
  under_review: HumanReviewIcon,
  rejected: AlertIcon,
  approved: CheckIcon,
  cancelled: CloseIcon,
};

/** Reading position; anything unknown sorts after everything named. */
function statusRank(status: string): number {
  const index = STATUS_ORDER.indexOf(status);

  return index === -1 ? STATUS_ORDER.length : index;
}

/**
 * A column of the orders table.
 *
 * Same shape `components/ui/Table` declares, on purpose: the page keeps that
 * component's vocabulary so a reader moving between the two screens reads one
 * idea, and so the columns could move back if the phone layout ever lands there.
 */
type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  /** Wraps the value in <bdi> and aligns it to the end — for money and counts. */
  numeric?: boolean;
  /**
   * على الهاتف: هذه الخليّةُ عنوانُ البطاقة — بلا تسميةٍ فوقَها وبعرضِ البطاقة.
   *
   * ⚠️ التسميةُ فوقَها كانت تُعيدُ ما تقولُه الخليّةُ نفسُها. «الطلب: ٨ حصص»
   * سطرانِ لمعلومةٍ واحدة، وثمنُهما على شاشةِ هاتفٍ هو أن يصيرَ الصفُّ الواحدُ
   * أطولَ من الشاشة، فلا يُرى منه اثنانِ معاً.
   */
  lead?: boolean;
};

/** «حصة واحدة» · «حصتان» · «٤ حصص» · «١٢ حصة» — the bands live in `counted()`. */
function sessions(count: number): string {
  return counted(count, {
    one: "حصة واحدة",
    two: "حصتان",
    few: "حصص",
    many: "حصة",
    other: "حصة",
  });
}

/** «طلب واحد» · «طلبان» · «٤ طلبات» · «١٢ طلباً» — five bands, `other` required. */
function orderCount(count: number): string {
  return counted(count, {
    one: "طلب واحد",
    two: "طلبان",
    few: "طلبات",
    many: "طلباً",
    other: "طلب",
  });
}

/** The headline of the «الطلب» cell, and the line under it. */
function bought(order: Order): { title: string; detail: string | null } {
  const intent = order.subscription;

  if (intent !== null) {
    return {
      // The plan's own title is what the buyer READ when they bought; the facts
      // beneath it are derived, so they stay true whatever the title says.
      title: intent.planTitle,
      detail: [
        // 036 -- the snapshot's own shape, read from the order and not from
        // the plan row: a plan edited while a bank transfer is in review
        // must not change what the buyer was sold.
        planShape({ duration_days: intent.durationDays, session_count: intent.sessionCount }),
        SESSION_TYPE_LABELS[intent.sessionType],
        intent.cohortName,
      ]
        .filter((part): part is string => Boolean(part))
        .join(" · "),
    };
  }

  if (order.kind === "credits" && order.credits != null) {
    return { title: sessions(order.credits), detail: KIND_LABELS.credits };
  }

  return { title: KIND_LABELS[order.kind] ?? "—", detail: null };
}

/*
 * ⚠️ THE BUYER'S SCREEN, AND THE APPROVE/REJECT BUTTONS LEFT IT ON 2026-09-03.
 *
 * Approving a course order writes the enrolment, the enrolment is taught, and
 * spec 014 pays the teacher for teaching it — so the payee was the one
 * witnessing that their own money had arrived. `payments.approve` moved to the
 * platform's finance officer, and the decision is made in `/admin` on the orders
 * screen, where the receipt is opened beside the amount.
 *
 * ⚠️ AND THE CONTROL WAS NEVER PERMISSION-GATED HERE AT ALL. It rendered on
 * `orders.some(o => OPEN_STATUSES.includes(o.status))` — "is any row still open"
 * — which is true of a STUDENT looking at their own unpaid order. So every
 * student was offered «اعتماد» on the payment they had just made, and learned
 * the product did not know who they were when the server refused it. Deriving an
 * audience in TypeScript is the two-spellings defect the comment about
 * `payer_name` below already refuses by name.
 */

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");
  /** How each payer says they paid, by order uuid. Bank transfer until told. */
  const [methods, setMethods] = useState<Record<string, string>>({});
  /** Which status the strip is showing. `all` until the reader narrows it. */
  const [filter, setFilter] = useState("all");

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Order[] }>("/orders")
      .then((res) => setOrders(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const replace = (uuid: string, patch: Partial<Order>) =>
    setOrders((prev) => prev.map((o) => (o.uuid === uuid ? { ...o, ...patch } : o)));

  const uploadReceipt = async (uuid: string, file: File) => {
    setBusy(uuid);
    setError("");
    try {
      const updated = await api.upload<Order>(`/orders/${uuid}/receipt`, (() => {
        const form = new FormData();
        form.append("receipt", file);
        // ⚠️ THE FIELD THE API HAS ACCEPTED SINCE THE GATEWAY LANDED, AND THAT
        // NOTHING ASKED FOR. It is optional on purpose — an older client must
        // not be refused — so every receipt arrived stamped "bank transfer",
        // including the wallet ones, and `mobile_wallet` was a value the product
        // could not produce. A column filled by nobody is not half an
        // implementation; it is one that reads as finished.
        form.append("method", methods[uuid] ?? "bank_transfer");
        return form;
      })());
      replace(uuid, updated);
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر رفع الإيصال."));
    } finally {
      setBusy(null);
    }
  };

  /*
   * What is still owed, which is the question this screen exists to answer and
   * did not.
   *
   * ⚠️ `is_mine`, BECAUSE A STAFF READER SEES OTHER PEOPLE'S ORDERS HERE. A total
   * built from every visible row would tell the finance officer that they
   * personally owe the platform every outstanding payment on it.
   *
   * ⚠️ AND ONE CURRENCY OR NO TOTAL AT ALL. Adding QAR to anything else produces
   * a number that is money in no currency, printed under a label saying it is
   * what the reader must pay. A missing total is a gap; a wrong one is a lie,
   * and the product has exactly one currency today so the gap is unreachable.
   *
   * `rejected` is deliberately inside the sum: nothing there was accepted as
   * paid, so it is still owed — the same reasoning that puts it in
   * `OPEN_STATUSES` and brings «ادفع الآن» back on a refusal.
   */
  const owed = useMemo(() => {
    const open = orders.filter((o) => o.is_mine && OPEN_STATUSES.includes(o.status));
    const currencies = new Set(open.map((o) => o.currency));

    if (open.length === 0 || currencies.size !== 1) return null;

    return {
      minor: open.reduce((sum, o) => sum + o.amount_minor, 0),
      currency: open[0].currency,
      count: open.length,
    };
  }, [orders]);

  /*
    The payer's column exists only for staff, and the SERVER decides that: the
    key is absent from a buyer's own payload (`orders.view_all` gates it), so
    testing the data is the same question as testing the permission — with one
    answer instead of two. Re-deriving "am I staff?" in TypeScript is the
    two-spellings defect this repository keeps paying for.

    ⚠️ ASKED OF THE WHOLE LIST, NEVER OF THE FILTERED ONE. Narrowing to a status
    whose rows happen to be the officer's own would otherwise drop the column
    they are reading the page for, and bring it back when they widen again.
  */
  const seesPayer = orders.some((o) => o.payer_name != null || o.payer_email != null);

  /*
   * ⚠️ ONLY THE STATUSES THAT ARE ACTUALLY THERE. A tile reading «٠ مرفوض» on
   * somebody with one pending order is four-fifths of a control strip that
   * cannot do anything — and «الكل» stays equal to the tiles beside it by
   * construction rather than by a list somebody has to keep in step.
   */
  const tiles = useMemo(
    () => [
      "all",
      ...[...new Set(orders.map((o) => o.status))].sort((a, b) => statusRank(a) - statusRank(b)),
    ],
    [orders],
  );

  const visible = filter === "all" ? orders : orders.filter((o) => o.status === filter);

  /*
   * ⛔ THE TABLE IS BUILT HERE AND NOT WITH `components/ui/Table`, AND THE REASON
   * IS THE PHONE. That component is one shape at every width: seven columns
   * inside `overflow-x-auto`, so a payer on a 360px screen reads «الطلب» and
   * drags sideways for the amount, again for the status, again for the button
   * that takes their money. It is right for the thirty other screens that use
   * it, and a second shape added THERE would be two spellings of a shared
   * component — the defect this repository records over and over.
   *
   * ⚠️ ONE DOM, RESHAPED BY CSS — NOT A TABLE AND A CARD LIST SIDE BY SIDE. Two
   * render paths mean every cell's rule written twice, and in jsdom (which has no
   * layout) BOTH would be present, so every assertion finds two elements and the
   * suite breaks over a change that was purely visual.
   *
   * ⚠️ AND THE FOUR STATES STILL COME FROM `components/ui/states/`. What `Table`
   * gave this page was loading, error, empty and retry in one place; those are
   * the three components it itself renders, imported directly. Re-implementing
   * them here is what would have made this a regression.
   */
  const columns: Column<Order>[] = [
    ...(seesPayer
      ? [
          {
            key: "payer",
            header: "الدافع",
            render: (o: Order) => (
              <div className="flex flex-col items-start">
                <span>{o.payer_name ?? "—"}</span>
                {o.payer_email && (
                  <span className="text-xs text-ink-muted">{o.payer_email}</span>
                )}
              </div>
            ),
          } satisfies Column<Order>,
        ]
      : []),
    {
      key: "bought",
      header: "الطلب",
      lead: true,
      render: (o) => {
        const { title, detail } = bought(o);
        const Icon = KIND_ICONS[o.kind] ?? OrdersIcon;

        return (
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-ink">
              <Icon className="h-5 w-5" />
            </span>
            <div className="flex flex-col items-start">
              <span className="font-medium">{title}</span>
              {detail !== null && detail !== "" && (
                <span className="text-xs text-ink-muted">{detail}</span>
              )}
              {o.course_title !== null && (
                <span className="text-xs text-ink-muted">{o.course_title}</span>
              )}
              {/*
                ⚠️ **لمن هذا الطلب — لوليِّ الأمرِ وحدَه.** الاشتراكُ يُكتَبُ باسمِ
                الطالبِ والدفعُ باسمِ الدافع، فوليُّ أمرٍ لثلاثةِ أبناءٍ كانَ سيقرأُ
                ثلاثةَ صفوفٍ بنفسِ الباقةِ ونفسِ المبلغِ ولا شيءَ يفرّقُ بينها.
                والخادمُ يُرسِلُ المفتاحَ لمن أنشأَ الطلبَ نيابةً عن غيرِه فقط، فمن
                يشتري لنفسِه لا يُقالُ له اسمُه.
              */}
              {o.for_student_name !== undefined && (
                <span className="text-xs font-medium text-primary-ink">
                  لـ {o.for_student_name}
                </span>
              )}
            </div>
          </div>
        );
      },
    },
    {
      key: "amount_minor",
      header: "المبلغ",
      numeric: true,
      render: (o) => (
        // ⚠️ `tabular-nums`: the amounts sit in one column and are compared down
        // it, and proportional digits make «٤٥٠» and «١١١» different widths for
        // the same number of figures.
        <span className="font-semibold tabular-nums">
          {formatMinorMoney(o.amount_minor, o.currency)}
        </span>
      ),
    },
    {
      key: "status",
      header: "الحالة",
      render: (o) => (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge status={o.status} />
          {/* The promise, shown only while it is still owed (FR-023). A payer
              who uploaded a receipt and sees nothing but "قيد المراجعة" has no
              way to tell waiting from being forgotten. */}
          {o.review_sla_hours !== null && o.has_receipt && (
            <span className="flex items-center gap-1 text-xs text-ink-muted">
              <ClockIcon className="h-3.5 w-3.5" />
              تُراجَع خلال {counted(o.review_sla_hours, { ...NOUNS.hours, two: "ساعتين" })}
            </span>
          )}
          {/* ⚠️ THE REASON WAS ON THE PAYLOAD AND ON NO SCREEN. `rejection_reason`
              has been in the `Order` type all along and was rendered nowhere, so
              a payer read «مرفوض» and had to ask by message what was wrong with
              it — while the officer had typed the answer (FR-032). */}
          {o.status === "rejected" && o.rejection_reason !== null && (
            /* ⚠️ مقيَّدُ العرض. السببُ جملةٌ يكتبُها الموظّفُ بحرّيّة، وبلا سقفٍ
               يمدُّ عمودَ «الحالة» حتّى يبتلعَ الجدول — وعلى شاشةٍ عريضةٍ يصيرُ
               سطراً واحداً طولُه نصفُ متر. */
            <span className="flex max-w-[16rem] items-start gap-1 text-xs text-danger-ink">
              <AlertIcon className="mt-px h-3.5 w-3.5 shrink-0" />
              <span className="text-pretty">{o.rejection_reason}</span>
            </span>
          )}
        </div>
      ),
    },
    {
      key: "receipt",
      header: "الإيصال",
      render: (o) => {
        /*
         * ⚠️ VIEWING AND REPLACING ARE NOT EXCLUSIVE, AND TREATING THEM AS SUCH
         * TRAPPED THE PAYER. The moment a receipt existed the view branch won,
         * so somebody who uploaded the wrong image — the previous transfer, a
         * blurred photograph, the wrong account — had no way to send the right
         * one and could only wait to be refused. The server has always accepted
         * a second upload while the order is undecided (`Order::acceptsReceipt`)
         * and every reader takes `latestReceipt()`, so the new image is what the
         * officer decides on. The old one is kept as the record of what was
         * refused, and shown to nobody.
         */
        const mayReplace = o.is_mine && OPEN_STATUSES.includes(o.status);

        return (
          /* ⚠️ صفٌّ يلتفُّ لا عمودٌ من ثلاثةِ ضوابط. الرابطُ والمنتقي والرافعُ
             ثلاثةُ أشكالٍ مختلفةٍ فوقَ بعضِها كانت أكثرَ ما يبدو غيرَ منتهٍ في
             الجدول؛ وهي الآنَ ثلاثُ حبّاتٍ بحدٍّ واحدٍ وارتفاعٍ واحد. */
          <div className="flex flex-wrap items-center gap-1.5">
            {o.receipt_url && !(o.is_mine && o.status === "rejected") && (
              <a
                href={o.receipt_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2 py-1 text-xs font-medium text-ink transition duration-200 hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
              >
                <EyeIcon className="h-3.5 w-3.5" />
                عرض الإيصال
              </a>
            )}

            {mayReplace && (
              <>
                {/* Asked BEFORE the file, because the file picker is a one-way
                    door: once it closes the upload is already on its way, and a
                    method chosen afterwards would be one chosen for the next
                    receipt. */}
                <label htmlFor={`method-${o.uuid}`} className="sr-only">
                  وسيلة الدفع
                </label>
                <Select
                  id={`method-${o.uuid}`}
                  value={methods[o.uuid] ?? "bank_transfer"}
                  onChange={(e) => setMethods((prev) => ({ ...prev, [o.uuid]: e.target.value }))}
                  disabled={busy === o.uuid}
                  chevron="sm"
                  className="rounded-lg border border-line bg-surface-raised px-2 py-1 text-xs text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                >
                  {/* No «بوابة دفع» here: a gateway payment leaves no receipt to
                      upload, and offering it beside a file picker would invite a
                      receipt for a payment the platform already watched happen. */}
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="mobile_wallet">محفظة إلكترونية</option>
                </Select>
                <label className="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-primary/40 bg-primary-soft px-2 py-1 text-xs font-medium text-primary-ink transition duration-200 hover:border-primary hover:bg-primary hover:text-white focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary motion-reduce:transition-none">
                  <UploadIcon className="h-3.5 w-3.5" />
                  {busy === o.uuid
                    ? "جارٍ الرفع…"
                    : o.has_receipt
                      ? "استبدل الإيصال"
                      : "ارفع الإيصال"}
                  <input
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    className="sr-only"
                    disabled={busy === o.uuid}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      e.target.value = "";
                      if (file) uploadReceipt(o.uuid, file);
                    }}
                  />
                </label>
              </>
            )}

            {!o.receipt_url && !mayReplace && (
              <span className="text-xs text-ink-muted">—</span>
            )}
          </div>
        );
      },
    },
    /*
     * ⚠️ THE ONLY WAY IN TO THE PAYMENT SCREEN, and without it that screen is
     * unreachable: /billing/pay needs an order uuid, and nothing else on the
     * platform hands one over. A page with no inbound link is a page nobody
     * visits, however correct it is.
     *
     * Shown for the payer's own open orders only. A settled order has nothing
     * left to pay, and someone else's is not theirs to settle.
     *
     * ⚠️ AND A STANDING RECEIPT TAKES IT AWAY — reported 2026-09-06. A payer who
     * had wired the money and uploaded the proof was still offered «ادفع الآن»
     * beside «قيد المراجعة»: the same amount, invited a second time, through a
     * gateway that would really take it. What they need there is to wait, or to
     * replace the image — both in the column beside this one.
     *
     * `rejected` is the exception and it is the same logic reversed: the receipt
     * was refused, so nothing has been accepted as paid and the gateway is a
     * legitimate way out rather than a second payment.
     */
    {
      key: "pay",
      header: "السداد",
      render: (o) =>
        o.is_mine
        && OPEN_STATUSES.includes(o.status)
        && (o.status === "rejected" || !o.has_receipt) ? (
          <Link
            href={`/billing/pay?order=${o.uuid}`}
            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white transition duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0"
          >
            <WalletIcon className="h-4 w-4" />
            ادفع الآن
          </Link>
        ) : (
          <span className="text-xs text-ink-muted">—</span>
        ),
    },
    {
      key: "date",
      header: "التاريخ",
      render: (o) => <span className="whitespace-nowrap text-ink-muted">{formatDate(o.created_at)}</span>,
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader Icon={OrdersIcon} title="الطلبات" />

      {error && (
        <p role="alert" className="rounded-lg bg-danger/15 p-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      {/*
        ⚠️ **الترتيبُ هو ترتيبُ الأسئلة، والبطاقةُ تأخذُ العرضَ كلَّه.** «كم عليَّ»
        ثمّ «إلى أين أحوّل» ثمّ «أيُّ طلبٍ هو طلبي» — وجهةُ التحويلِ فوقَ المُرشِّحِ
        لأنّها ما يُفعَلُ الآن، والمُرشِّحُ ملاصقٌ للجدولِ لأنّه يعملُ فيه وحدَه:
        ضابطٌ بعيدٌ عمّا يضبطُه هو ضابطٌ لا يُربَطُ بأثرِه.
      */}
      <div className="flex flex-wrap items-center gap-3">
        {owed !== null && (
          <div
            /*
              ⚠️ A NAMED REGION, NOT A BARE `div`. The figure it carries is the
              same string the amount column prints, so «the total» and «one row's
              amount» are indistinguishable to anything reading the page by text
              — a screen reader landing on it, and a test asserting on it. The
              name is what separates them. `status` because the number really is
              a summarised state: it moves when a row's status does.
            */
            role="status"
            aria-label="المطلوب سداده"
            className="animate-float-in flex items-center gap-3 rounded-2xl border border-accent/40 bg-accent/10 px-4 py-3"
          >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent/20 text-ink">
              <WalletIcon className="h-5 w-5" />
            </span>
            <div className="flex flex-col items-start">
              <span className="text-xs text-ink-muted">
                المطلوب سداده — {orderCount(owed.count)}
              </span>
              <bdi className="text-xl font-bold tabular-nums text-ink">
                {formatMinorMoney(owed.minor, owed.currency)}
              </bdi>
            </div>
          </div>
        )}
      </div>

      {/* ⚠️ هنا تحديداً يُرفَعُ الإيصال — و`‎/billing/pay` تُحيلُ إلى هذه الصفحةِ
          بنصِّها. فالوجهةُ تُقرأُ حيثُ يُنفَّذُ التحويل، لا حيثُ يُوصَفُ فقط. */}
      <TransferDestination />

      {/*
        ⚠️ RENDERED ONLY OVER ROWS THAT EXIST. A filter strip above an empty
        table is five buttons that all lead to the same «لا طلبات» — chrome
        offering choices with no consequence, on the screen of somebody who has
        never bought anything.

        ⚠️ AND IT IS `role="group"` WITH PRESSED STATE ON EACH BUTTON, not a list
        of links. The narrowing is client-side and leaves no URL behind it, so a
        reader with a screen reader is told which one is current by
        `aria-pressed` — the only thing that says so, since the visual cue is a
        border colour.
      */}
      {!loading && !failed && orders.length > 0 && (
        <div role="group" aria-label="تصفية الطلبات بالحالة" className="flex flex-wrap gap-2">
          {tiles.map((key, index) => {
            const Icon = key === "all" ? OrdersIcon : (STATUS_ICONS[key] ?? OrdersIcon);
            const count =
              key === "all" ? orders.length : orders.filter((o) => o.status === key).length;
            const label = key === "all" ? "الكل" : statusLabel(key);
            const active = filter === key;

            return (
              <button
                key={key}
                type="button"
                onClick={() => setFilter(key)}
                aria-pressed={active}
                /* The stagger is 40ms a tile and stops at the fifth — the
                   reduced-motion block zeroes the delay as well as the duration,
                   so a reader who asked for less motion gets them all at once
                   rather than five invisible buttons for 200ms. */
                style={{ animationDelay: `${index * 40}ms` }}
                className={`animate-float-in flex items-center gap-2.5 rounded-2xl border px-3.5 py-2 text-start transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0 ${
                  active
                    ? "border-primary bg-primary-soft"
                    : "border-line bg-surface-raised hover:border-primary"
                }`}
              >
                <span
                  className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${
                    TONE_CLASSES[key === "all" ? "info" : statusTone(key)]
                  }`}
                >
                  <Icon className="h-4 w-4" />
                </span>
                <span className="flex flex-col">
                  {/* ⚠️ ARABIC-INDIC, like every other number this product
                      prints. `counted()` does the same inside itself; here the
                      label beside it already carries the noun, so the numeral is
                      all that is needed. */}
                  <span className="text-sm font-semibold tabular-nums text-ink">
                    {arabicNumber(count)}
                  </span>
                  <span className="text-xs text-ink-muted">{label}</span>
                </span>
              </button>
            );
          })}
        </div>
      )}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : visible.length === 0 ? (
        /* ⚠️ TWO DIFFERENT EMPTIES. «لا طلبات في سجلّك» over a list the reader
           just narrowed is the product telling them they have never bought
           anything, a minute after showing them nine rows.

           Reachable through exactly one path now that the tiles are derived:
           filter to «بانتظار الدفع», upload the receipt on the only row there,
           and the server's answer moves it to «قيد المراجعة» — the tile empties
           under a selection that is still pointing at it. */
        <EmptyState
          title={filter === "all" ? "لا طلبات في سجلّك" : `لا طلبات بحالة «${statusLabel(filter)}»`}
          description={
            filter === "all"
              ? "سيظهر هنا كل طلب شراء بحالته وإيصاله."
              : "اختر «الكل» من الشريط أعلاه لترى بقيّة طلباتك."
          }
        />
      ) : (
        <div
          /*
            ⛔ **`overflow-x-auto` وحدَه لا يمنعُ الصفحةَ من التزحلقِ جانبيّاً،
            و`min-w-max` داخلَه يُسرِّبُ عرضَه إلى الوثيقةِ كلِّها.** قِيسَ في
            المتصفّحِ ٢٠٢٦-٠٩-١٧: `documentElement.scrollWidth` ‏١٥٣٦ مقابلَ
            `clientWidth` ‏١٣٨٢ — شريطُ تزحلقٍ أفقيٌّ على الصفحةِ بأكملِها.
            وإلغاءُ `min-width` من الجدولِ وحدَه يُعيدُها إلى ‏١٣٨٢ بالضبط، بينما
            `min-width: 0` على الحاوي أو على `main` أو على عنصرِ الـflex **لا
            يغيّرُ شيئاً**: مساهمةُ المحتوى في العرضِ الأدنى تصعدُ فوقَ كلِّ ذلك.

            و`contain: inline-size` هو ما قِيسَ أنّه يُوقِفُها (‏١٣٨٢): يجعلُ عرضَ
            الحاوي مستقلّاً عن محتواه، وهو تعريفُ حاويةِ التزحلقِ أصلاً. فيبقى
            وعدُ `min-w-max` — «الأعمدةُ لا تُسحَق، بل يُزحلَقُ الجدول» — صحيحاً
            دونَ أن تدفعَ الوثيقةُ ثمنَه.

            ⚠️ ولا يراهُ أيُّ اختبارٍ هنا: jsdom بلا تخطيط، فالعرضُ صفرٌ في كلِّ
            الحالات. وُجِدَ بالنظرِ إلى الشاشةِ ثمّ بالقياسِ في المتصفّح.
          */
          /* ⚠️ الصندوقُ من `md` فقط: على الهاتفِ كلُّ صفٍّ بطاقةٌ لها حدُّها، وحدٌّ
             حولَ الثمانيةِ معاً يجعلُها بطاقاتٍ داخلَ بطاقة. */
          className="[contain:inline-size] overflow-x-auto md:rounded-2xl md:border md:border-line md:bg-surface-raised"
        >
          {/*
            ⚠️ `role` MUTELY RESTATED ON EVERY PART, BECAUSE `display: block` TAKES
            TABLE SEMANTICS AWAY. A browser that is told a `<tr>` is a block stops
            treating it as a row, and the element silently becomes a generic box
            to a screen reader. On `md` and up the roles agree with the native
            ones and cost nothing; below it they are what keeps the grid a grid.
          */}
          <table role="table" className="block w-full text-sm md:table md:min-w-max">
            <caption className="sr-only">طلبات الشراء وحالتها وإيصالاتها</caption>

            {/*
              ⚠️ REMOVED ON A PHONE RATHER THAN HIDDEN, and the label moves INTO
              each cell. A header row read once at the top and then seven values
              read in order is a table nobody can follow by ear; «المبلغ» sitting
              directly above the amount is the same information where it is used.
            */}
            <thead
              role="rowgroup"
              className="hidden border-b border-line bg-surface md:table-header-group"
            >
              <tr role="row">
                {columns.map((col) => (
                  <th
                    key={col.key}
                    role="columnheader"
                    scope="col"
                    className={`px-4 py-3 text-xs font-medium tracking-wide text-ink-muted ${
                      col.numeric ? "text-end" : "text-start"
                    }`}
                  >
                    {col.header}
                  </th>
                ))}
              </tr>
            </thead>

            {/* ⚠️ فراغٌ بينَ البطاقاتِ على الهاتفِ، وخطٌّ فاصلٌ على الحاسوب. صفوفٌ
                يفصلُها خطٌّ وحدَه على شاشةِ هاتفٍ تُقرأُ قائمةً واحدةً طويلةً لا
                ثمانيةَ طلبات — والقارئُ يبحثُ عن طلبِه فيها بالعين. */}
            <tbody role="rowgroup" className="block space-y-3 md:table-row-group md:space-y-0 md:divide-y md:divide-line">
              {visible.map((o, index) => (
                <tr
                  key={o.uuid}
                  role="row"
                  /* The stagger stops at the sixth row: past that it is a page
                     that takes a quarter of a second to finish arriving, which
                     is a cost rather than a cue. */
                  style={{ animationDelay: `${Math.min(index, 5) * 40}ms` }}
                  /* بطاقةٌ لها حدٌّ وحشوةٌ على الهاتف، وصفُّ جدولٍ عاديٌّ من `md`:
                     الحدُّ والزوايا والحشوةُ كلُّها تُلغى هناك، فلا يُرسَمُ صندوقٌ
                     حولَ كلِّ صفٍّ في جدول. */
                  className="animate-float-in block rounded-2xl border border-line bg-surface-raised p-4 transition hover:bg-primary-soft/40 md:table-row md:rounded-none md:border-0 md:bg-transparent md:p-0"
                >
                  {columns.map((col) => (
                    <td
                      key={col.key}
                      role="cell"
                      /*
                        على الهاتف: العنوانُ بلا تسمية، وكلُّ ما بعدَه سطرٌ واحدٌ
                        تسميتُه في أوّلِه وقيمتُه في آخرِه — وهو الشكلُ الذي يُقرأُ
                        بمسحةِ عينٍ واحدةٍ من الأعلى إلى الأسفل. ومن `md` تعودُ
                        خلايا جدولٍ عاديّة.
                      */
                      className={`text-ink md:table-cell md:px-4 md:py-4 md:align-top ${
                        col.lead
                          ? "block border-b border-line pb-3 md:border-0 md:pb-4"
                          : "flex items-baseline justify-between gap-3 py-1.5 md:block md:py-4"
                      } ${col.numeric ? "md:text-end" : "text-start"}`}
                    >
                      {/*
                        ⚠️ A REAL ELEMENT, NOT `content: attr(data-label)`. A CSS
                        `content` that fails — a typo, a token that was never
                        defined — paints NOTHING and reports nothing, which is a
                        family of defect this repository has shipped four times.
                        A span that is there is a span a test can find.

                        ⚠️ ولا تسميةَ فوقَ عنوانِ البطاقة: «الطلب: ٨ حصص» سطرانِ
                        لمعلومةٍ واحدة، وثمنُهما صفٌّ أطولُ من شاشةِ الهاتف.
                      */}
                      {!col.lead && (
                        <span className="shrink-0 text-xs text-ink-muted md:hidden">
                          {col.header}
                        </span>
                      )}
                      {col.numeric ? <bdi>{col.render(o)}</bdi> : col.render(o)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
