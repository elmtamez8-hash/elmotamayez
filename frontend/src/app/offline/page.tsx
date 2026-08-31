import type { Metadata } from "next";

import { Card } from "@/components/ui/Card";

export const metadata: Metadata = {
  title: "لا يوجد اتصال",
};

/**
 * What the reader sees with no connection (spec 012 · US2 · T065).
 *
 * ⚠️ IT SAYS WHICH THINGS NEED A CONNECTION, RATHER THAN «حدث خطأ». A blank
 * screen or a bare apology leaves somebody standing in a corridor with no signal
 * wondering whether the app is broken, whether their booking went through, and
 * whether to try again — three questions a sentence answers.
 *
 * ⚠️ AND IT PROMISES NOTHING WE DO NOT DO. There is no write queue in this
 * product and there is deliberately not going to be one: every write here has a
 * guard that reads live state — a seat that may be taken, a balance that may have
 * moved, a session that may have been cancelled — so a booking replayed twenty
 * minutes later is a decision made against a world that no longer exists. Telling
 * the reader «we will send it when you are back» would be the one sentence on
 * this page that is false.
 *
 * A server component: it is static text, so it costs no JavaScript — which
 * matters more here than anywhere, because whoever reads it is on the worst
 * connection they have all day.
 */
export default function OfflinePage() {
  return (
    <main className="mx-auto flex min-h-screen max-w-lg items-center p-6">
      <Card as="section">
        <h1 className="mb-2 text-xl font-bold text-ink">لا يوجد اتصال بالإنترنت</h1>

        <p className="mb-4 text-sm text-ink-muted">
          هذه الصفحة ظهرت لأنّ جهازك غير متّصل الآن. ما فُتح سابقاً قد يبقى متاحاً،
          أمّا الآتي فيحتاج اتّصالاً:
        </p>

        <ul className="mb-4 space-y-2 text-sm text-ink">
          <li>• حجز حصّة أو الدخول إلى غرفة البثّ</li>
          <li>• مشاهدة الدروس والتسجيلات</li>
          <li>• تسليم اختبار أو واجب</li>
          <li>• الرصيد والمدفوعات والرسائل</li>
        </ul>

        <p className="text-sm text-ink-muted">
          لم يُفقد شيء ممّا كنت تعمل عليه على خوادمنا. أعِدْ المحاولة بعد عودة
          الاتّصال.
        </p>
      </Card>
    </main>
  );
}
