"use client";

import { useRef, useState } from "react";

import { AvatarCropper } from "@/components/settings/AvatarCropper";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Avatar } from "@/components/ui/Avatar";
import { Card } from "@/components/ui/Card";
import { useAuth } from "@/lib/auth-context";
import { loadImageFile } from "@/lib/avatar-crop";
import { userMessage } from "@/lib/errors";
import { profileApi } from "@/lib/profile";

/**
 * صورةُ الحسابِ — وضعاً وقصّاً وحذفاً.
 *
 * ⚠️ `teacher_profiles.photo_path` و`student_profiles.avatar_path` كانَ لكلٍّ
 * منهما قرّاءٌ — كشفُ الحضورِ وقائمةُ المجموعةِ والصفحةُ الأولى وبطاقةُ الكورس —
 * وبلا كاتبٍ واحدٍ في الشجرة. فالحرفُ الأوّلُ في دائرةٍ لم يكنْ احتياطاً بل الحالةَ
 * الوحيدة.
 *
 * ⚠️ والقديمةُ تُحذَفُ من القرصِ عندَ الاستبدال، خلافاً لإيصالِ الدفعِ الذي
 * يُحتفَظُ به: لا يُقرَّرُ على صورةِ حسابٍ شيءٌ ولا يُراجعُها أحد، فبقاؤها تخزينٌ
 * لا يشيرُ إليه شيء — وصورةُ وجهٍ باقيةٌ بعدَ أن أزالَها صاحبُها.
 *
 * ⚠️ **والاختيارُ لم يعدْ رفعاً.** كانَ اختيارُ ملفٍّ يرفعُه فوراً كما هو، فيقصُّه
 * المتصفّحُ بعدَها بـ`object-cover` **من المنتصف** — والوجهُ في صورةِ هاتفٍ نادراً
 * ما يكونُ في المنتصف. صارَ الاختيارُ يفتحُ مرحلةَ ضبطٍ في الإطارِ الدائريِّ
 * نفسِه الذي ستُعرَضُ فيه، والمرفوعُ هو ما رآهُ صاحبُه (بلاغُ ٢٠٢٦-٠٩-٠٦).
 *
 * ⚠️ والقصُّ هنا **تجربةٌ لا حدّ**: `SaveAccountPhoto` يُعيدُ ترميزَ كلِّ ما يصلُه
 * مربَّعاً ٥١٢ JPEG على أيِّ حال — يمحو EXIF بما فيه إحداثيّاتُ الالتقاط، ويقتلُ
 * الملفَّ المزدوج، ويجعلُ الحجمَ حدّاً لا رجاءً. مقصٌّ في المتصفّحِ وحدَه هو حدٌّ
 * على الجانبِ الذي لا يُوثَقُ به.
 */
export function AccountPhotoCard({
  initialUrl,
  name,
}: {
  initialUrl: string | null;
  name: string;
}) {
  const { refreshUser } = useAuth();
  const [url, setUrl] = useState(initialUrl);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [picked, setPicked] = useState<HTMLImageElement | null>(null);
  const input = useRef<HTMLInputElement>(null);

  /**
   * إغلاقُ مرحلةِ القصِّ، وإبطالُ عنوانِ `blob:` معها.
   *
   * ⚠️ هنا لا في `useEffect` للتنظيف. `reactStrictMode` مُفعَّلٌ افتراضاً في
   * Next، فيُشغِّلُ React أثرَ التطويرِ **مرّتَين** بتنظيفٍ بينهما: تنظيفٌ يُبطِلُ
   * العنوانَ يقتلُ صورةً ما زالت معروضةً على الشاشة، فتظهرُ دائرةٌ فارغةٌ في
   * التطويرِ وحدَه وتعملُ في الإنتاج — عائلةُ استدعاءِ pusher-js المزدوجِ نفسُها،
   * وقد كلّفتْ هذا المستودعَ عطبَين. والخروجُ من هذه المرحلةِ حدثانِ اثنانِ لا
   * أكثر، فتسميتُهما أوضحُ من أثرٍ يُخمِّنُ متى انتهى العرض.
   *
   * ⚠️ وإبطالٌ لا يقعُ أبداً ليس تسريباً صغيراً: بايتاتُ كلِّ صورةٍ جُرِّبَتْ تبقى
   * في الذاكرةِ إلى أن تُغلَقَ الصفحة، وصورُ الهواتفِ بالميغابايتات.
   */
  const closeCrop = () => {
    if (picked !== null) URL.revokeObjectURL(picked.src);

    setPicked(null);
  };

  const run = async (task: Promise<{ photo_url: string | null }>) => {
    setBusy(true);
    setError("");

    try {
      const { photo_url } = await task;

      setUrl(photo_url);
      closeCrop();

      /*
       * ⚠️ الشريطُ الجانبيُّ وقائمةُ الحسابِ يقرآنِ الصورةَ من `useAuth()`، وهو
       * محمَّلٌ مرّةً واحدةً عندَ إقلاعِ التطبيق. فبلا هذا السطرِ تتغيّرُ الصورةُ في
       * هذه البطاقةِ وحدَها **وتبقى القديمةُ في كلِّ صفحةٍ أخرى** إلى أن يُعادَ
       * تحميلُ التطبيقِ كلِّه — بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-٠٦.
       *
       * ولا يُنتظَرُ: البطاقةُ عرضتْ ما حفظَه الخادمُ سلفاً، والمزامنةُ خلفَها.
       */
      void refreshUser();
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
        WEBP، حتى ٤ ميغابايت — وتُحفظ مربّعة ٥١٢×٥١٢.
      </p>

      {error && (
        <div className="mb-4">
          <Alert tone="danger" title="تعذّر تحديث الصورة">
            {error}
          </Alert>
        </div>
      )}

      {picked !== null ? (
        <AvatarCropper
          image={picked}
          busy={busy}
          onCancel={closeCrop}
          onError={setError}
          onCrop={(blob) =>
            // اسمٌ ثابتٌ ونوعٌ ثابت: الخادمُ يُسمّي الملفَّ بنفسِه من الـuuid
            // ولاحقةٍ عشوائيّة، فاسمُ العميلِ لا يصلُ القرصَ أصلاً.
            void run(profileApi.savePhoto(new File([blob], "avatar.jpg", { type: "image/jpeg" })))
          }
        />
      ) : (
        <div className="flex items-center gap-4">
          {/* ⚠️ `Avatar` من الطقمِ لا نسخةٌ محلّيّة. كتبتُ هذه الدائرةَ بيدي أوّلَ
              مرّةٍ والمكوّنُ قائمٌ منذُ قبل — ونسختانِ من صورةِ الحسابِ تفترقانِ
              عندَ أوّلِ تعديل، وقد افترقتا فعلاً: `bg-surface-muted` غيرِ
              المعرَّفِ عاشَ في إحداهما بينما الأخرى تحملُ إصلاحَه. */}
          <Avatar url={url} name={name} size="lg" />

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

                // Cleared BEFORE the load starts: without it, picking the same
                // file twice fires no change event at all, so a cancelled crop
                // cannot be reopened with the same picture.
                event.target.value = "";

                if (!file) return;

                setError("");

                // ⚠️ وليس `void promise`: ملفٌّ تالفٌ أو صيغةٌ لا يفكُّها المتصفّحُ
                // يرفضُ هنا، وبلا التقاطٍ يبقى الزرُّ مضغوطاً بلا أثرٍ في الإنتاج.
                loadImageFile(file)
                  .then(setPicked)
                  .catch((err: unknown) => setError(userMessage(err)));
              }}
            />

            <Button
              type="button"
              variant="secondary"
              disabled={busy}
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
      )}
    </Card>
  );
}
