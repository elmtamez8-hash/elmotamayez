"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { offboarding, type TeacherOffboarding } from "@/lib/compliance";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

/**
 * A teacher asking to wind down their workspace (US6 · FR-032 … FR-037).
 *
 * ⚠️ THE BACKEND HALF SHIPPED WITH NO SCREEN AND NO ENDPOINT A TEACHER COULD
 * REACH — the whole flow sat behind a platform permission while the user story
 * reads "a teacher asks to leave". A page nothing links to is the same defect
 * wearing a URL, so this one is in the sidebar under «التدريس».
 *
 * ⚠️ AND THERE IS NO COMPLETE BUTTON HERE, DELIBERATELY. Completing an exit
 * revokes access, ends every membership and fixes the recordings' retention, and
 * FR-032 makes it conditional on money settled in both directions. A teacher
 * pressing their own would be signing off on their own settlement — the officer's
 * queue is where that happens.
 */
export default function OffboardingPage() {
  const [record, setRecord] = useState<TeacherOffboarding | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  /*
   * ⚠️ «THE LOAD FAILED» IS NOT «NO REQUEST YET», AND ONE null MEANT BOTH.
   * A refusal leaves `record` null, which is the same state as a teacher who has
   * simply not asked to leave — so a reader the server had just answered 403 was
   * shown the refusal banner AND the five consequences AND a live «اطلبِ الخروج»
   * beneath it. Reported by a signed-in STUDENT, who has no students of their
   * own for the copy to be about.
   */
  const [refused, setRefused] = useState(false);

  const load = useCallback(() => {
    offboarding
      .show()
      .then((response) => {
        setRecord(response.data);
        setError(null);
        setRefused(false);
      })
      /*
       * ⚠️ THE REASON IS SHOWN, NOT SWALLOWED. `.catch(() => undefined)` on a
       * fetch renders a permanently blank page with the explanation sitting unread
       * in the response — the rule against raw errors is not a rule for showing
       * nothing, and `userMessage()` is what turns one into a sentence.
       */
      .catch((cause: unknown) => {
        setError(userMessage(cause));
        setRefused(true);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  async function submit() {
    setSubmitting(true);

    try {
      const response = await offboarding.request();
      setRecord(response.data);
      setConfirming(false);
      setError(null);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">إنهاء النشاط على المنصّة</h1>
        {!refused && (
          // Addressed to somebody with students. A reader who was just refused
          // has none, and telling them what happens to «طلابك» is the same
          // mistake as the button below, in a smaller font.
          <p className="text-sm text-ink-muted">
            ما يحدث لطلابك ولمحتواك ولمستحقّاتك حين تقرّر المغادرة.
          </p>
        )}
      </header>

      {error !== null && (
        <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
          {error}
        </Alert>
      )}

      {loading ? (
        <Card>
          <p className="text-sm text-ink-muted">جارٍ التحميل…</p>
        </Card>
      ) : refused ? (
        // The banner above already carries the reason. Nothing else belongs
        // here: the consequences are addressed to a teacher's students, and a
        // button that answers 403 on every press is an invitation to keep
        // pressing it.
        null
      ) : record === null ? (
        <Card>
          {/*
            ⚠️ THE CONSEQUENCES ARE LISTED BEFORE THE BUTTON, NOT AFTER IT. This
            request notifies every student in the workspace and pulls the public
            listing — the first of those reaches other people's phones, and a
            confirmation dialog asking "are you sure?" over an unexplained action
            is not consent.
          */}
          <h2 className="text-base font-semibold text-ink">قبل أن تطلب</h2>
          <ul className="mt-3 space-y-2 text-sm text-ink-muted">
            <li>• يُخطَر طلابك وأولياء أمورهم بمهلةٍ معلَنة قبل توقّف الخدمة.</li>
            <li>• تتوقّف صفحتك العامّة عن استقبال طلابٍ جدد فوراً.</li>
            <li>• يبقى ما دفع له طلابك متاحاً لهم حتى نهاية مدّتهم.</li>
            <li>• تُجهَّز نسخةٌ من محتواك تجدها في صفحة «خصوصيّتي».</li>
            <li>• لا يكتمل الخروج قبل حسم مستحقّاتك القائمة لك وعليك.</li>
          </ul>

          {confirming ? (
            <div className="mt-4 space-y-3">
              <Alert tone="warning" title="سيصل الإخطار إلى طلابك">
                لا يمكن التراجع عن الإخطار بعد إرساله.
              </Alert>
              <div className="flex gap-2">
                <Button
                  variant="danger"
                  onClick={submit}
                  loading={submitting}
                  loadingLabel="جارٍ الإرسال…"
                >
                  أكّد طلب الخروج
                </Button>
                <Button variant="secondary" onClick={() => setConfirming(false)}>
                  تراجعْ
                </Button>
              </div>
            </div>
          ) : (
            <div className="mt-4">
              <Button variant="danger" onClick={() => setConfirming(true)}>
                اطلبِ الخروج
              </Button>
            </div>
          )}
        </Card>
      ) : (
        <Card>
          <h2 className="text-base font-semibold text-ink">حالة طلبك</h2>

          <dl className="mt-3 space-y-2 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-ink-muted">الحالة</dt>
              <dd className="text-ink">{record.status_label}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-ink-muted">المستحقّات</dt>
              {/*
                ⚠️ A WORD, NEVER A NUMBER. The server answers whether the books are
                square and never by how much: a teacher's balance on a screen is
                the platform's half of a rate, solvable from the other side.
              */}
              <dd className="text-ink">{record.dues_cleared ? "حُسمت" : "قيد الحسم"}</dd>
            </div>
            {record.notice_ends_at !== null && (
              <div className="flex justify-between gap-4">
                <dt className="text-ink-muted">تنتهي المهلة</dt>
                <dd className="text-ink">{formatDate(record.notice_ends_at)}</dd>
              </div>
            )}
            <div className="flex justify-between gap-4">
              <dt className="text-ink-muted">أُخطِر الطلاب</dt>
              <dd className="text-ink">
                {record.students_notified_at === null ? "لم يُرسَل بعد" : "نعم"}
              </dd>
            </div>
          </dl>

          {/*
            The archive is an ordinary export request, so the link goes to the page
            that already downloads one — signed per press, expiring, allowlisted. A
            second download path here would be a second place to get the expiry
            wrong.
          */}
          <div className="mt-4">
            <Button href="/settings/privacy" variant="secondary">
              {record.content_export_ready ? "نزِّلْ نسخةَ محتواك" : "تابعْ تجهيز نسخة محتواك"}
            </Button>
          </div>

          {record.completed_at === null && (
            <p className="mt-4 text-sm text-ink-muted">
              يكتمل الخروج بقرارٍ من إدارة المنصّة بعد حسم المستحقّات وانتهاء المهلة.
            </p>
          )}
        </Card>
      )}
    </div>
  );
}
