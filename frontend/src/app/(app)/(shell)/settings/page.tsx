"use client";

import { useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { useAuth } from "@/lib/auth-context";
import type { User } from "@/lib/types";
import { Card } from "@/components/ui/Card";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";

export default function SettingsPage() {
  const { user } = useAuth();

  const [profile, setProfile] = useState({
    first_name: user?.first_name ?? "",
    last_name: user?.last_name ?? "",
    email: user?.email ?? "",
  });
  const [profileSaved, setProfileSaved] = useState(false);
  const [profileError, setProfileError] = useState("");
  const [profileFields, setProfileFields] = useState<Record<string, string>>({});
  const [savingProfile, setSavingProfile] = useState(false);

  const [pw, setPw] = useState({
    current_password: "",
    password: "",
    password_confirmation: "",
  });
  const [pwSaved, setPwSaved] = useState(false);
  const [pwError, setPwError] = useState("");
  const [pwFields, setPwFields] = useState<Record<string, string>>({});
  const [savingPw, setSavingPw] = useState(false);

  const submitProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileError("");
    setProfileFields({});
    setProfileSaved(false);
    setSavingProfile(true);

    try {
      await api.patch<User>("/auth/me", profile);
      setProfileSaved(true);
    } catch (err: unknown) {
      // 422 lands under the field it belongs to; everything else goes to the
      // banner. Mixing them puts "you are not signed in" under a name input.
      const fields = fieldErrors(err);
      if (Object.keys(fields).length > 0) setProfileFields(fields);
      else setProfileError(userMessage(err));
    } finally {
      setSavingProfile(false);
    }
  };

  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setPwError("");
    setPwFields({});
    setPwSaved(false);
    setSavingPw(true);

    try {
      await api.post("/auth/change-password", pw);
      setPw({ current_password: "", password: "", password_confirmation: "" });
      setPwSaved(true);
    } catch (err: unknown) {
      const fields = fieldErrors(err);
      if (Object.keys(fields).length > 0) setPwFields(fields);
      else setPwError(userMessage(err));
    } finally {
      setSavingPw(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <h2 className="text-2xl font-bold text-ink">الإعدادات</h2>

      <Card as="section">
        <h3 className="mb-4 font-semibold text-ink">الملف الشخصي</h3>
        <form onSubmit={submitProfile} className="space-y-4">
          {profileError && <Alert tone="danger" title={profileError} />}
          {profileSaved && <Alert tone="success" title="حُفِظت بياناتك." />}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <TextField
              id="first_name"
              label="الاسم الأول"
              value={profile.first_name}
              onChange={(v) => setProfile({ ...profile, first_name: v })}
              error={profileFields.first_name}
              autoComplete="given-name"
              required
            />
            <TextField
              id="last_name"
              label="اسم العائلة"
              value={profile.last_name}
              onChange={(v) => setProfile({ ...profile, last_name: v })}
              error={profileFields.last_name}
              autoComplete="family-name"
              required
            />
          </div>

          <TextField
            id="email"
            label="البريد الإلكتروني"
            type="email"
            value={profile.email}
            onChange={(v) => setProfile({ ...profile, email: v })}
            error={profileFields.email}
            autoComplete="email"
            required
          />

          <Button type="submit" loading={savingProfile} loadingLabel="جارٍ الحفظ…">
            احفظ التغييرات
          </Button>
        </form>
      </Card>

      <Card as="section">
        <h3 className="mb-1 font-semibold text-ink">الإشعارات</h3>
        <p className="mb-4 text-sm text-ink-muted">
          اختر القنوات لكل فئة من الإشعارات، واضبط فترة الهدوء.
        </p>
        <Button href="/settings/notifications" variant="secondary">
          إعدادات الإشعارات
        </Button>
      </Card>

      <Card as="section">
        <h3 className="mb-4 font-semibold text-ink">تغيير كلمة المرور</h3>
        <form onSubmit={submitPassword} className="space-y-4">
          {pwError && <Alert tone="danger" title={pwError} />}
          {pwSaved && <Alert tone="success" title="غُيِّرت كلمة المرور." />}

          <TextField
            id="current_password"
            label="كلمة المرور الحالية"
            type="password"
            value={pw.current_password}
            onChange={(v) => setPw({ ...pw, current_password: v })}
            error={pwFields.current_password}
            autoComplete="current-password"
            required
          />
          <TextField
            id="password"
            label="كلمة المرور الجديدة"
            type="password"
            value={pw.password}
            onChange={(v) => setPw({ ...pw, password: v })}
            error={pwFields.password}
            hint="ثمانية أحرف على الأقل."
            autoComplete="new-password"
            minLength={8}
            required
          />
          <TextField
            id="password_confirmation"
            label="تأكيد كلمة المرور الجديدة"
            type="password"
            value={pw.password_confirmation}
            onChange={(v) => setPw({ ...pw, password_confirmation: v })}
            error={pwFields.password_confirmation}
            autoComplete="new-password"
            required
          />

          <Button type="submit" loading={savingPw} loadingLabel="جارٍ التغيير…">
            غيّر كلمة المرور
          </Button>
        </form>
      </Card>
    </div>
  );
}
