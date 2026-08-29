"use client";

import { useCallback, useEffect, useState } from "react";
import { PurchaseDialog } from "@/components/store/PurchaseDialog";
import { StoreItemCard } from "@/components/store/StoreItemCard";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { store, type StoreItem, type StorePurchase } from "@/lib/store";
import { api } from "@/lib/api";
import { SelectField } from "@/components/ui/Field";
import type { Enrollment } from "@/lib/types";

/**
 * The buyer's store: what they have bought, and what they can buy.
 *
 * ⚠️ «افتح» IS A DESTRUCTIVE CONTROL AND SAYS SO BEFORE THE PRESS. Opening a
 * digital purchase stamps `first_accessed_at` on the server, which closes the
 * refund window for ever — so it goes through `ConfirmButton`, whose two-press
 * arm is the whole point: a control that cannot be taken back must be asked
 * about first, and telling the buyer afterwards is not telling them.
 *
 * ⚠️ AND `is_refundable` IS READ, NEVER RECOMPUTED. The two conditions are a
 * clock and a flag; a button enabled by a client-side guess is a button the
 * server then refuses, with the buyer reading a failure they were invited into.
 */
export default function StorePage() {
  const [purchases, setPurchases] = useState<StorePurchase[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [buying, setBuying] = useState<StoreItem | null>(null);

  /*
   * ⚠️ THE CATALOGUE NEEDS A WORKSPACE, AND THE STUDENT'S OWN ENROLMENTS ARE
   * WHERE IT COMES FROM. `/store/catalogue` is filtered by an explicit workspace
   * rather than by the global scope — which is inert for a student — so a picker
   * built from anything else would offer teachers the endpoint then answers
   * nothing for. `EnrollmentResource` already sends `workspace_uuid`, so this
   * costs no new endpoint and leaves no surface unreachable.
   */
  const [teachers, setTeachers] = useState<Array<{ uuid: string; name: string }>>([]);
  const [teacherUuid, setTeacherUuid] = useState("");
  const [catalogue, setCatalogue] = useState<StoreItem[]>([]);
  const [problem, setProblem] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    store
      .purchases()
      .then((res) => {
        setPurchases(res.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    api
      .get<{ data: Enrollment[] }>("/enrollments")
      .then((res) => {
        const seen = new Map<string, string>();

        for (const enrolment of res.data ?? []) {
          if (enrolment.workspace_uuid) {
            seen.set(enrolment.workspace_uuid, enrolment.teacher_name ?? "مدرّسك");
          }
        }

        setTeachers([...seen].map(([uuid, name]) => ({ uuid, name })));
      })
      .catch(() => setTeachers([]));
  }, []);

  useEffect(() => {
    if (!teacherUuid) {
      setCatalogue([]);

      return;
    }

    store
      .catalogue(teacherUuid)
      .then((res) => setCatalogue(res.data ?? []))
      .catch(() => setCatalogue([]));
  }, [teacherUuid]);

  async function open(purchase: StorePurchase) {
    setProblem(null);
    setNote(null);

    try {
      await store.open(purchase.uuid);
      setNote("فُتِح الملف. تجده الآن في مكتبتك.");
      load();
    } catch (error) {
      setProblem(userMessage(error));
    }
  }

  async function refund(purchase: StorePurchase) {
    setProblem(null);
    setNote(null);

    try {
      await store.refund(purchase.uuid, store.newIdempotencyKey());
      setNote("سُجِّل طلب الاسترداد، ويصلك المبلغ بالطريقة التي دفعتَ بها.");
      load();
    } catch (error) {
      setProblem(userMessage(error));
    }
  }

  if (buying) {
    return (
      <PurchaseDialog
        item={buying}
        onDone={() => {
          setBuying(null);
          setNote("سُجِّل طلبك. يبدأ التسليم فور اعتماد دفعتك.");
          load();
        }}
        onCancel={() => setBuying(null)}
      />
    );
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">مشترياتي</h1>
        <p className="mt-1 text-sm text-ink-muted">
          الكتب والمذكّرات التي اشتريتَها من مدرّسيك، وحالة كلٍّ منها.
        </p>
      </header>

      {problem && <Alert tone="danger" title={problem} />}
      {note && <Alert tone="success" title={note} />}

      {state === "loading" && <RowsSkeleton count={4} />}
      {state === "error" && <ErrorState onRetry={load} />}

      {state === "ready" && purchases.length === 0 && (
        <EmptyState
          title="لا مشتريات بعد"
          description="تظهر هنا كل نسخة تشتريها من متجر مدرّسك، رقمية كانت أو مطبوعة."
        />
      )}

      {state === "ready" && purchases.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2">
          {purchases.map((purchase) => (
            <li key={purchase.uuid}>
              <Card>
                <div className="space-y-3">
                  <div className="flex items-start justify-between gap-3">
                    <h2 className="text-base font-semibold text-ink">
                      {purchase.item?.title ?? "منتَج"}
                    </h2>

                    {purchase.refunded_at ? (
                      <Badge tone="neutral">مُسترَدّ</Badge>
                    ) : purchase.is_fulfilled ? (
                      <Badge tone="success">جاهز</Badge>
                    ) : (
                      <Badge tone="warning">بانتظار اعتماد الدفع</Badge>
                    )}
                  </div>

                  <p className="text-sm text-ink-muted">
                    {formatMinorMoney(purchase.total_minor, purchase.currency)}
                    {purchase.quantity > 1 ? ` · ${purchase.quantity} نسخ` : ""}
                  </p>

                  {purchase.shipment && (
                    <p className="text-sm text-ink">
                      الشحنة: {purchase.shipment.status_label}
                    </p>
                  )}

                  {purchase.is_fulfilled &&
                    !purchase.refunded_at &&
                    purchase.item?.kind === "digital" && (
                      <ConfirmButton
                        confirmLabel={
                          purchase.opened_at
                            ? "تأكيد الفتح"
                            : "بالفتح يسقط الاسترداد — اضغط للتأكيد"
                        }
                        onConfirm={() => void open(purchase)}
                      >
                        افتح الملف
                      </ConfirmButton>
                    )}

                  {purchase.is_refundable && (
                    <Button variant="ghost" size="sm" onClick={() => refund(purchase)}>
                      استرداد
                    </Button>
                  )}
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}

      {teachers.length > 0 && (
        <section className="space-y-4">
          <h2 className="text-lg font-semibold text-ink">متاجر مدرّسيك</h2>

          <SelectField
            id="teacher"
            label="اختر المدرّس"
            value={teacherUuid}
            onChange={setTeacherUuid}
            options={teachers.map((teacher) => ({ value: teacher.uuid, label: teacher.name }))}
            placeholder="—"
          />

          {teacherUuid && catalogue.length === 0 && (
            <EmptyState
              title="لا منتجات في هذا المتجر"
              description="لم يعرض هذا المدرّس كتباً أو مذكّرات بعد."
            />
          )}

          {catalogue.length > 0 && (
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {catalogue.map((item) => (
                <li key={item.uuid}>
                  <StoreItemCard item={item} onBuy={setBuying} />
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <p className="text-xs text-ink-muted">
        الاسترداد متاح خلال ٤٨ ساعة من الشراء وما لم يُفتَح الملف بعد. النسخة
        المطبوعة تُشحن بعد اعتماد الدفع، وتصلك رسالة مع كل خطوة.
      </p>
    </div>
  );
}
