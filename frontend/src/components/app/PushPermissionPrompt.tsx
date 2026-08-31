"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { errorMessage } from "@/lib/api";
import {
  currentSubscription,
  isInstalled,
  isIosSafari,
  pushConfigured,
  pushSupported,
  subscribeToPush,
  unsubscribeFromPush,
} from "@/lib/push";

type State = "loading" | "absent" | "unsupported" | "ios-needs-install" | "off" | "on" | "denied";

/**
 * Turn push on for this device — and say plainly when it cannot be turned on
 * (spec 012 · US2 · T076 · FR-035).
 *
 * ⚠️ REFUSING THE BROWSER PROMPT DISABLES NOTHING. The bell keeps ringing, every
 * notification keeps arriving in the feed, and this card simply goes back to
 * offering. The server half of that promise needs no code at all: with no
 * subscription `WebPushChannel::canReach()` answers false and the delivery is
 * recorded SKIPPED, never failed.
 *
 * ⚠️ AND «غير مدعوم» IS THE WRONG SENTENCE ON AN iPHONE. Safari gives a web page
 * in a tab no push at all — only an app added to the home screen gets it — so the
 * APIs are genuinely missing before installation and `pushSupported()` cannot
 * tell «this browser never will» from «not yet». Told it is unsupported, the
 * person stops looking; told to install it first, they have something to do. Same
 * family as the secure-context message on the camera button: naming the wrong
 * cause sends somebody hunting a setting that is not the problem.
 */
export function PushPermissionPrompt() {
  const [state, setState] = useState<State>("loading");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    const resolve = async () => {
      /*
        ⚠️ AN UNCONFIGURED SERVER IS NOT AN UNSUPPORTED BROWSER, AND THE CARD
        APPEARS EITHER WAY: the settings column is derived from
        `ChannelRegistry::implemented()`, which asks whether the class is TAGGED
        and never whether it has keys. Told «this browser does not support push»,
        somebody on a perfectly capable Chrome goes looking through their browser
        settings for a fault that is in ours. Nothing to say, so nothing shown.
      */
      if (!pushConfigured()) {
        if (!cancelled) setState("absent");

        return;
      }

      if (!pushSupported()) {
        const next: State = isIosSafari() && !isInstalled() ? "ios-needs-install" : "unsupported";
        if (!cancelled) setState(next);

        return;
      }

      if (Notification.permission === "denied") {
        if (!cancelled) setState("denied");

        return;
      }

      const subscription = await currentSubscription();
      if (!cancelled) setState(subscription ? "on" : "off");
    };

    void resolve();

    return () => {
      cancelled = true;
    };
  }, []);

  const enable = async () => {
    setBusy(true);
    setError("");

    try {
      const granted = await subscribeToPush();
      // Declining is an answer, not a failure: no message, the card just stays
      // as it was and can be tried again later.
      setState(granted ? "on" : Notification.permission === "denied" ? "denied" : "off");
    } catch (err) {
      setError(errorMessage(err, "تعذّر تفعيل الإشعارات الفوريّة على هذا الجهاز."));
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    setBusy(true);
    setError("");

    try {
      await unsubscribeFromPush();
      setState("off");
    } catch (err) {
      setError(errorMessage(err, "تعذّر إيقاف الإشعارات الفوريّة على هذا الجهاز."));
    } finally {
      setBusy(false);
    }
  };

  if (state === "loading" || state === "absent") return null;

  return (
    <div className="space-y-3">
      {error && <Alert tone="danger" title={error} />}

      {state === "unsupported" && (
        <p className="text-sm text-ink-muted">
          هذا المتصفّح لا يدعم الإشعارات الفوريّة. كلُّ إشعاراتك تصلك في الجرس داخل
          المنصّة كالمعتاد.
        </p>
      )}

      {state === "ios-needs-install" && (
        <p className="text-sm text-ink-muted">
          على الآيفون والآيباد تعمل الإشعارات الفوريّة بعد إضافة المنصّة إلى الشاشة
          الرئيسيّة: من زرّ المشاركة في سفاري اختَرْ «إضافة إلى الشاشة الرئيسيّة»، ثمّ
          افتحْها من هناك وعُدْ إلى هذه الصفحة.
        </p>
      )}

      {state === "denied" && (
        <p className="text-sm text-ink-muted">
          الإشعارات محجوبة لهذا الموقع من إعدادات المتصفّح، ولا يمكن طلبها من هنا
          مرّة أخرى. اسمحْ بها من إعدادات الموقع في متصفّحك ثمّ أعِدْ تحميل الصفحة.
        </p>
      )}

      {state === "off" && (
        <>
          <p className="text-sm text-ink-muted">
            فعِّلْها ليصلك تنبيه الحصّة والرصيد والحساب على هذا الجهاز دون فتح
            الموقع. لا يظهر نصُّ الرسالة على شاشة القفل، بل عنوانها ورابطها فقط.
          </p>
          <Button onClick={enable} loading={busy} loadingLabel="جارٍ التفعيل…">
            فعِّلِ الإشعارات على هذا الجهاز
          </Button>
        </>
      )}

      {state === "on" && (
        <>
          <p className="text-sm text-ink-muted">
            الإشعارات الفوريّة مفعّلة على هذا الجهاز. إيقافها هنا يخصّ هذا الجهاز
            وحدَه؛ اختيارُك للفئات في الجدول أعلاه يبقى كما هو.
          </p>
          <Button variant="secondary" onClick={disable} loading={busy} loadingLabel="جارٍ الإيقاف…">
            أوقِفْها على هذا الجهاز
          </Button>
        </>
      )}
    </div>
  );
}
