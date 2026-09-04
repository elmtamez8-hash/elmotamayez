"use client";

import { useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";

type Profile = {
  slug: string | null;
  is_publicly_listed: boolean;
  /*
   * ⚠️ NOT THE SAME FLAG AS `is_publicly_listed`, AND THE BLOG RIDES ON THIS ONE.
   * The derived flag needs approval AND participation; an article is public on
   * the workspace's participation alone. So a teacher still in review can be
   * publishing articles the whole internet can read while their own profile
   * answers 404 — which is the one thing this card must not leave them to guess.
   */
  workspace_participates_in_marketplace: boolean;
};

/**
 * The teacher editing the segment their public profile lives at.
 *
 * A card on /settings rather than a page of its own: this is one field, and a
 * route that exists to hold one field is a place nobody navigates to. It sits
 * beside the profile and password cards, which is where someone looks when they
 * want to change how they appear.
 *
 * ⚠️ It renders NOTHING for an account with no teacher profile — a student, a
 * guardian, an admin. The check is the API's answer, not the user's
 * `platform_role`: the role says what someone signed up as, the profile says
 * whether a public page exists to rename.
 */
export function PublicProfileUrlCard() {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [slug, setSlug] = useState("");
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;

    api
      .get<Profile>("/teacher/profile")
      .then((data) => {
        if (!active) return;
        setProfile(data);
        setSlug(data.slug ?? "");
      })
      // Swallowed on purpose. A student opening /settings gets a 403 here, and
      // the card simply does not appear — an error banner about a teacher
      // profile would be noise on a page that is working correctly for them.
      .catch(() => {});

    return () => {
      active = false;
    };
  }, []);

  if (profile === null) return null;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError("");
    setFields({});
    setSaved(false);
    setSaving(true);

    try {
      const data = await api.put<{ slug: string }>("/teacher/profile/slug", {
        slug,
      });
      setProfile({ ...profile, slug: data.slug });
      setSlug(data.slug);
      setSaved(true);
    } catch (err: unknown) {
      // 422 under the field, everything else in the banner. "هذا الرابط
      // مستخدم بالفعل" belongs beside the input; "انتهت جلستك" does not.
      const messages = fieldErrors(err);
      if (Object.keys(messages).length > 0) setFields(messages);
      else setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card as="section">
      <h3 className="mb-1 font-semibold text-ink">رابط ملفك العام</h3>
      <p className="mb-4 text-sm text-ink-muted">
        هذا هو العنوان الذي يصل منه الطلاب وأولياء الأمور إلى صفحتك.
      </p>

      {profile.slug === null ? (
        // No profile URL yet: the application has not been approved, so there is
        // nothing to rename. Saying that is more useful than a disabled input.
        <Alert tone="info" title="لم يُنشر ملفك بعد">
          يظهر الرابط هنا فور اعتماد طلبك، ويمكنك تعديله وقتها.
        </Alert>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert tone="danger" title={error} />}
          {saved && <Alert tone="success" title="حُفِظ الرابط الجديد." />}

          <TextField
            id="slug"
            label="الرابط"
            value={slug}
            onChange={setSlug}
            error={fields.slug}
            hint="حروف إنجليزية صغيرة وأرقام وشرطة (-) فقط، من 3 إلى 60 حرفاً."
            maxLength={60}
            required
          />

          {/* The whole URL, not just the segment. A teacher deciding what to put
              in the box is deciding what a parent reads in a WhatsApp message,
              and the box alone does not show them that. */}
          <p className="break-all text-sm text-ink-muted" dir="ltr">
            <span className="text-ink-muted">/teachers/</span>
            <span className="font-semibold text-ink">{slug || "…"}</span>
          </p>

          {profile.workspace_participates_in_marketplace && (
            // Spec 011 · T117 — the decision was to reuse the marketplace flag
            // rather than add a «publish the blog» switch of its own, and the
            // price of reusing it is that nothing on any screen said so:
            // withdrawing from the marketplace takes the blog down in the same
            // breath, silently, and putting an article back up is not something
            // the teacher can do from the blog screen.
            <Alert tone="info" title="مقالاتُك منشورةٌ للعموم أيضاً">
              ما تنشره في المدوّنة يُقرأ بلا تسجيل ويظهر لمحرّكات البحث، ما دامت
              أنتَ معروضٌ في السوق. خروجُك من السوق يُنزِلُ المدوّنةَ معه.
            </Alert>
          )}

          {!profile.is_publicly_listed && (
            // A slug on an unlisted profile answers 404 to everyone but its
            // owner. Without this the teacher sets their link, opens it in a
            // private window, and reads the 404 as a bug in the field they just
            // used — when the cause is a workspace that has not joined the
            // marketplace, or an approval still pending.
            <Alert tone="info" title="ملفك غير معروض في السوق حالياً">
              الرابط لن يفتح للزوّار حتى يُنشر ملفك. يمكنك ضبطه من الآن.
            </Alert>
          )}

          {slug !== profile.slug && (
            // ⚠️ Said BEFORE saving, not after. There is no redirect from the old
            // slug — keeping one would reserve every past spelling for ever — so
            // this is the only moment the teacher can weigh it.
            <Alert tone="warning" title="الرابط القديم سيتوقّف">
              أي رابط شاركته من قبل بالعنوان الحالي لن يعمل بعد الحفظ.
            </Alert>
          )}

          <Button
            type="submit"
            loading={saving}
            loadingLabel="جارٍ الحفظ…"
            disabled={slug === profile.slug}
          >
            احفظ الرابط
          </Button>
        </form>
      )}
    </Card>
  );
}
