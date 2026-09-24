"use client";

import { useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { useAuth } from "@/lib/auth-context";
import type { User } from "@/lib/types";
import { Card } from "@/components/ui/Card";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { PasswordField, TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { BellIcon, LockIcon, SettingsIcon, ShieldIcon, UserIcon } from "@/components/icons";
import { PublicProfileUrlCard } from "@/components/marketplace/PublicProfileUrlCard";

export default function SettingsPage() {
  const { user, refreshUser } = useAuth();

  const [profile, setProfile] = useState({
    first_name: user?.first_name ?? "",
    last_name: user?.last_name ?? "",
    email: user?.email ?? "",
  });
  /*
  | ⛔ A NEW ADDRESS COSTS THE CURRENT PASSWORD. The address is where the reset
  | link goes, so changing it is changing who owns the account — the server
  | refuses it without the password, and this is where the screen asks for it.
  | Compared against the address as last SAVED, not as first loaded, so a second
  | edit after a successful one asks again.
  */
  const [savedEmail, setSavedEmail] = useState(user?.email ?? "");
  const [emailPassword, setEmailPassword] = useState("");
  const emailChanged =
    profile.email.trim().toLowerCase() !== savedEmail.trim().toLowerCase();
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
      await api.patch<User>(
        "/auth/me",
        emailChanged ? { ...profile, current_password: emailPassword } : profile,
      );
      setSavedEmail(profile.email);
      setEmailPassword("");
      setProfileSaved(true);
      await refreshUser();
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
      <PageHeader Icon={SettingsIcon} title="الإعدادات" />

      <Card as="section">
        <div className="mb-4">
          <SectionHeading
            id="settings-profile"
            Icon={UserIcon}
            title="الملف الشخصي"
          />
        </div>
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

          {emailChanged && (
            <PasswordField
              id="profile_current_password"
              label="كلمة المرور الحالية"
              value={emailPassword}
              onChange={setEmailPassword}
              error={profileFields.current_password}
              hint="تغيير البريد الإلكتروني يحتاج كلمة مرورك الحالية."
              autoComplete="current-password"
              required
            />
          )}

          <Button type="submit" loading={savingProfile} loadingLabel="جارٍ الحفظ…">
            احفظ التغييرات
          </Button>
        </form>
      </Card>

      {/* Renders nothing for an account with no teacher profile, so it is
          mounted unconditionally. It decides for itself whom to ask: a learner
          is never asked (the 403 it used to take on every student's settings
          page), and everybody else gets the API's own answer. */}
      <PublicProfileUrlCard />

      {/*
        ⚠️ الرابطُ الداخلُ إلى شاشةٍ لم تكنْ موجودة. كلُّ ما تكتبُه الخطوةُ
        الثانيةُ من معالجِ الانضمامِ كانَ يُكتَبُ مرّةً ولا يُعدَّلُ إلّا من لوحةِ
        الإدارة، وصورةُ الحسابِ لم يكنْ لها كاتبٌ في الشجرةِ أصلاً — وصفحةٌ لا
        يصلُ إليها رابطٌ صفحةٌ لا يفتحُها أحدٌ مهما كانت صحيحة.
      */}
      <Card as="section" interactive>
        <div className="mb-4">
          <SectionHeading
            id="settings-my-profile"
            Icon={UserIcon}
            title="ملفّي وصورتي"
            description="صورة حسابك، وما تُدرّسه ولمن ووصفك — أو صفّك الدراسي ومنطقتك."
          />
        </div>
        <Button href="/settings/profile" variant="secondary">
          افتح ملفّي
        </Button>
      </Card>

      <Card as="section" interactive>
        <div className="mb-4">
          <SectionHeading
            id="settings-notifications"
            Icon={BellIcon}
            title="الإشعارات"
            description="اختر القنوات لكل فئة من الإشعارات، واضبط فترة الهدوء."
          />
        </div>
        <Button href="/settings/notifications" variant="secondary">
          إعدادات الإشعارات
        </Button>
      </Card>

      <Card as="section" interactive>
        <div className="mb-4">
          <SectionHeading
            id="settings-security"
            Icon={ShieldIcon}
            title="الأجهزة والجلسات"
            description="راجع الأجهزة المسجَّل دخولها إلى حسابك، وأنهِ أي جلسة لا تعرفها."
          />
        </div>
        <Button href="/settings/security" variant="secondary">
          الأجهزة والجلسات
        </Button>
      </Card>

      <Card as="section">
        <div className="mb-4">
          <SectionHeading
            id="settings-password"
            Icon={LockIcon}
            title="تغيير كلمة المرور"
          />
        </div>
        <form onSubmit={submitPassword} className="space-y-4">
          {pwError && <Alert tone="danger" title={pwError} />}
          {pwSaved && <Alert tone="success" title="غُيِّرت كلمة المرور." />}

          <PasswordField
            id="current_password"
            label="كلمة المرور الحالية"
            value={pw.current_password}
            onChange={(v) => setPw({ ...pw, current_password: v })}
            error={pwFields.current_password}
            autoComplete="current-password"
            required
          />
          <PasswordField
            id="password"
            label="كلمة المرور الجديدة"
            value={pw.password}
            onChange={(v) => setPw({ ...pw, password: v })}
            error={pwFields.password}
            hint="ثمانية أحرف على الأقل."
            autoComplete="new-password"
            minLength={8}
            required
          />
          <PasswordField
            id="password_confirmation"
            label="تأكيد كلمة المرور الجديدة"
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
