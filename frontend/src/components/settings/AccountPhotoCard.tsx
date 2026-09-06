"use client";

import { useRef, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { profileApi } from "@/lib/profile";

/**
 * صورةُ الحسابِ — وهي أوّلُ ما يكتبُها في هذا المنتَج.
 *
 * ⚠️ `teacher_profiles.photo_path` و`student_profiles.avatar_path` كانَ لكلٍّ
 * منهما أربعةُ قرّاءٍ — كشفُ الحضورِ وقائمةُ المجموعةِ والصفحةُ الأولى وبطاقةُ
 * الكورس — وبلا كاتبٍ واحدٍ في الشجرة. فالحرفُ الأوّلُ في دائرةٍ لم يكنْ احتياطاً
 * بل الحالةَ الوحيدة.
 *
 * ⚠️ والقديمةُ تُحذَفُ من القرصِ عندَ الاستبدال، خلافاً لإيصالِ الدفعِ الذي
 * يُحتفَظُ به: لا يُقرَّرُ على صورةِ حسابٍ شيءٌ ولا يُراجعُها أحد، فبقاؤها تخزينٌ
 * لا يشيرُ إليه شيء — وصورةُ وجهٍ باقيةٌ بعدَ أن أزالَها صاحبُها.
 */
export function AccountPhotoCard({
  initialUrl,
  name,
}: {
  initialUrl: string | null;
  name: string;
}) {
  const [url, setUrl] = useState(initialUrl);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const input = useRef<HTMLInputElement>(null);

  const run = async (task: Promise<{ photo_url: string | null }>) => {
    setBusy(true);
    setError("");

    try {
      const { photo_url } = await task;

      setUrl(photo_url);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card as="section">
      <h3 className="mb-1 font-semibold text-ink">صورة الحساب</h3>
      <p className="mb-4 text-sm text-ink-muted">
        تظهر في كشف الحضور وقائمة مجموعتك وصفحتك العامة. الصيغ: JPG أو PNG أو
        WEBP، حتى ٤ ميغابايت.
      </p>

      {error && (
        <div className="mb-4">
          <Alert tone="danger" title="تعذّر تحديث الصورة">
            {error}
          </Alert>
        </div>
      )}

      <div className="flex items-center gap-4">
        {url === null ? (
          <span
            className="flex size-20 items-center justify-center rounded-full bg-primary-soft text-2xl font-bold text-primary-ink"
            aria-hidden="true"
          >
            {name.charAt(0)}
          </span>
        ) : (
          // A plain <img>: `next/image` would route a user-supplied path through
          // `sharp`, whose advisories this tree accepts precisely because no
          // such path reaches it.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={url}
            alt=""
            className="size-20 rounded-full object-cover"
          />
        )}

        <div className="flex flex-col gap-2">
          {/*
            ⚠️ زرٌّ يفتحُ حقلاً مخفيّاً، لا حقلُ ملفٍّ عارٍ: `<input type="file">`
            يرسمُه كلُّ متصفّحٍ بنصِّه هو («Choose File»)، بالإنجليزيّةِ وسطَ
            صفحةٍ عربيّةٍ ومن اليسارِ إلى اليمين.
          */}
          <input
            ref={input}
            type="file"
            accept=".jpg,.jpeg,.png,.webp"
            className="sr-only"
            disabled={busy}
            onChange={(event) => {
              const file = event.target.files?.[0];

              // Cleared BEFORE the upload starts: without it, picking the same
              // file twice fires no change event at all, so a failed upload
              // cannot be retried with the same picture.
              event.target.value = "";

              if (file) void run(profileApi.savePhoto(file));
            }}
          />

          <Button
            type="button"
            variant="secondary"
            loading={busy}
            loadingLabel="جارٍ الرفع…"
            onClick={() => input.current?.click()}
          >
            {url === null ? "ارفع صورة" : "غيّر الصورة"}
          </Button>

          {url !== null && (
            <Button
              type="button"
              variant="ghost"
              disabled={busy}
              onClick={() => void run(profileApi.removePhoto())}
            >
              أزِل الصورة
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
}
