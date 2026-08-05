"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, auth, setToken, errorMessage, fieldErrors } from "@/lib/api";
import { COUNTRIES, DEFAULT_COUNTRY } from "@/lib/countries";
import type { Taxonomy } from "@/lib/public-api";
import { PhoneInput, toE164 } from "@/components/ui/PhoneInput";
import { Button } from "@/components/ui/Button";

const FIELD =
  "w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

const STEPS = [
  "بيانات أساسية",
  "بيانات مهنية",
  "المستندات",
  "السعر والتوفّر",
] as const;

const DAYS = ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

const LANGUAGES = [
  { value: "ar", label: "العربية" },
  { value: "en", label: "الإنجليزية" },
  { value: "fr", label: "الفرنسية" },
];

interface ApplicationState {
  status: string;
  current_step: number;
  step_data: Record<string, Record<string, unknown>>;
  rejection_reason: string | null;
}

interface Slot {
  day_of_week: number;
  start_time: string;
  end_time: string;
}

function ProgressBar({ step }: { step: number }) {
  return (
    <ol className="mb-8 flex gap-2" aria-label={`الخطوة ${step} من ${STEPS.length}`}>
      {STEPS.map((label, index) => {
        const number = index + 1;
        const done = number < step;
        const current = number === step;

        return (
          <li key={label} className="flex-1">
            <div
              className={`mb-1.5 h-1.5 rounded-full ${
                done || current ? "bg-primary" : "bg-line"
              }`}
              aria-hidden="true"
            />
            <span
              className={`text-xs ${current ? "font-bold text-primary-ink" : "text-ink-muted"}`}
              aria-current={current ? "step" : undefined}
            >
              {number}. {label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null;

  return (
    <p id={`${id}-error`} className="mt-1 text-sm text-danger-ink">
      {message}
    </p>
  );
}

export function TeacherSignupWizard({
  subjects,
  gradeLevels,
}: {
  subjects: Taxonomy[];
  gradeLevels: Taxonomy[];
}) {
  const router = useRouter();

  const [application, setApplication] = useState<ApplicationState | null>(null);
  const [step, setStep] = useState(1);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);
  const [restoring, setRestoring] = useState(true);

  // Step 1
  const [account, setAccount] = useState({
    first_name: "",
    last_name: "",
    email: "",
    password: "",
    password_confirmation: "",
    country: DEFAULT_COUNTRY.code,
    terms_accepted: false,
  });
  const [dial, setDial] = useState(DEFAULT_COUNTRY.dial);
  const [phone, setPhone] = useState("");

  // Step 2
  const [professional, setProfessional] = useState({
    subjects: [] as string[],
    grade_levels: [] as string[],
    years_experience: 1,
    qualifications: "",
    teaching_languages: ["ar"] as string[],
    headline: "",
    bio: "",
  });

  // Step 3
  const [acknowledged, setAcknowledged] = useState(false);

  // Step 4
  const [rate, setRate] = useState("");
  const [slots, setSlots] = useState<Slot[]>([
    { day_of_week: 0, start_time: "16:00", end_time: "18:00" },
  ]);

  const idempotencyKey = useMemo(
    () => globalThis.crypto?.randomUUID?.() ?? String(Date.now()),
    [],
  );

  // FR-070: someone who already started resumes where they stopped instead of
  // re-typing four screens.
  useEffect(() => {
    const token = typeof window !== "undefined" ? localStorage.getItem("auth_token") : null;

    if (!token) {
      setRestoring(false);

      return;
    }

    api
      .get<{ application: ApplicationState }>("/teacher/application")
      .then(({ application }) => {
        setApplication(application);
        setStep(application.current_step);
        hydrate(application);
      })
      .catch(() => undefined)
      .finally(() => setRestoring(false));
    // Runs once on mount; hydrate only writes state.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function hydrate(state: ApplicationState) {
    const two = state.step_data?.step_2;

    if (two) {
      setProfessional({
        subjects: (two.subjects as string[]) ?? [],
        grade_levels: (two.grade_levels as string[]) ?? [],
        years_experience: (two.years_experience as number) ?? 1,
        qualifications: ((two.qualifications as string[]) ?? []).join("\n"),
        teaching_languages: (two.teaching_languages as string[]) ?? ["ar"],
        headline: (two.headline as string) ?? "",
        bio: (two.bio as string) ?? "",
      });
    }

    const three = state.step_data?.step_3;
    if (three) setAcknowledged(three.documents_acknowledged === true);

    const four = state.step_data?.step_4;
    if (four) {
      setRate((four.hourly_rate as string) ?? "");
      setSlots(((four.availability as Slot[]) ?? []).map((slot) => ({
        ...slot,
        start_time: slot.start_time.slice(0, 5),
        end_time: slot.end_time.slice(0, 5),
      })));
    }
  }

  async function send(request: () => Promise<{ application: ApplicationState }>) {
    setErrors({});
    setBanner("");
    setLoading(true);

    try {
      const { application } = await request();

      setApplication(application);
      setStep(application.current_step);
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      setErrors(fields);
      if (Object.keys(fields).length === 0) {
        setBanner(errorMessage(err, "تعذّر حفظ البيانات، حاول مرة أخرى."));
      }
    } finally {
      setLoading(false);
    }
  }

  const submitStepOne = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!account.terms_accepted) {
      setErrors({ terms_accepted: "يجب الموافقة على الشروط والأحكام." });

      return;
    }

    setErrors({});
    setBanner("");
    setLoading(true);

    try {
      const result = await auth.registerTeacher(
        { ...account, phone: toE164(dial, phone) },
        idempotencyKey,
      );

      if (result.token) setToken(result.token);
      setApplication(result.application);
      setStep(result.application.current_step);
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      setErrors(fields);
      if (Object.keys(fields).length === 0) {
        setBanner(errorMessage(err, "تعذّر إنشاء الحساب، حاول مرة أخرى."));
      }
    } finally {
      setLoading(false);
    }
  };

  const submitStepTwo = (event: React.FormEvent) => {
    event.preventDefault();

    return send(() =>
      api.put("/teacher/application/step-2", {
        ...professional,
        qualifications: professional.qualifications
          .split("\n")
          .map((line) => line.trim())
          .filter(Boolean),
      }),
    );
  };

  const submitStepThree = (event: React.FormEvent) => {
    event.preventDefault();

    return send(() =>
      api.put("/teacher/application/step-3", { documents_acknowledged: acknowledged }),
    );
  };

  const submitStepFour = async (event: React.FormEvent) => {
    event.preventDefault();

    await send(() =>
      api.put("/teacher/application/step-4", { hourly_rate: rate, availability: slots }),
    );

    setLoading(true);

    try {
      await api.post("/teacher/application/submit");
      router.push("/signup/teacher/submitted");
    } catch (err: unknown) {
      setBanner(errorMessage(err, "تعذّر إرسال الطلب."));
      setLoading(false);
    }
  };

  const toggle = (key: "subjects" | "grade_levels" | "teaching_languages", value: string) =>
    setProfessional((current) => ({
      ...current,
      [key]: current[key].includes(value)
        ? current[key].filter((item) => item !== value)
        : [...current[key], value],
    }));

  if (restoring) {
    return <p className="py-16 text-center text-ink-muted">جارٍ تحميل طلبك…</p>;
  }

  return (
    <div>
      <ProgressBar step={step} />

      {application?.status === "changes_requested" && application.rejection_reason && (
        <p role="status" className="mb-6 rounded-xl bg-accent/10 p-4 text-sm text-ink">
          <strong className="block font-bold">طلب فريقنا الأكاديمي تعديلاً:</strong>
          {application.rejection_reason}
        </p>
      )}

      {banner && (
        <p role="alert" className="mb-6 rounded-xl bg-danger/10 p-3 text-sm text-danger-ink">
          {banner}
        </p>
      )}

      {step === 1 && (
        <form onSubmit={submitStepOne} noValidate className="space-y-5">
          <div className="grid gap-5 sm:grid-cols-2">
            <div>
              <label htmlFor="t-first" className="mb-1 block text-sm font-medium text-ink">
                الاسم الأول
              </label>
              <input
                id="t-first"
                value={account.first_name}
                onChange={(e) => setAccount({ ...account, first_name: e.target.value })}
                required
                className={FIELD}
              />
              <FieldError id="t-first" message={errors.first_name} />
            </div>

            <div>
              <label htmlFor="t-last" className="mb-1 block text-sm font-medium text-ink">
                اسم العائلة
              </label>
              <input
                id="t-last"
                value={account.last_name}
                onChange={(e) => setAccount({ ...account, last_name: e.target.value })}
                className={FIELD}
              />
            </div>
          </div>

          <div>
            <label htmlFor="t-email" className="mb-1 block text-sm font-medium text-ink">
              البريد الإلكتروني
            </label>
            <input
              id="t-email"
              type="email"
              dir="ltr"
              value={account.email}
              onChange={(e) => setAccount({ ...account, email: e.target.value })}
              required
              className={FIELD}
            />
            <FieldError id="t-email" message={errors.email} />
          </div>

          <PhoneInput
            id="t-phone"
            dial={dial}
            number={phone}
            onDialChange={setDial}
            onNumberChange={setPhone}
            error={errors.phone}
          />

          <div className="grid gap-5 sm:grid-cols-2">
            <div>
              <label htmlFor="t-country" className="mb-1 block text-sm font-medium text-ink">
                الدولة
              </label>
              <select
                id="t-country"
                value={account.country}
                onChange={(e) => setAccount({ ...account, country: e.target.value })}
                className={FIELD}
              >
                {COUNTRIES.map((country) => (
                  <option key={country.code} value={country.code}>
                    {country.name_ar}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="t-password" className="mb-1 block text-sm font-medium text-ink">
                كلمة المرور
              </label>
              <input
                id="t-password"
                type="password"
                value={account.password}
                onChange={(e) => setAccount({ ...account, password: e.target.value })}
                required
                minLength={8}
                className={FIELD}
              />
              <FieldError id="t-password" message={errors.password} />
            </div>
          </div>

          <div>
            <label htmlFor="t-password2" className="mb-1 block text-sm font-medium text-ink">
              تأكيد كلمة المرور
            </label>
            <input
              id="t-password2"
              type="password"
              value={account.password_confirmation}
              onChange={(e) =>
                setAccount({ ...account, password_confirmation: e.target.value })
              }
              required
              className={FIELD}
            />
          </div>

          <div>
            <label className="flex items-start gap-3 text-sm text-ink">
              <input
                type="checkbox"
                checked={account.terms_accepted}
                onChange={(e) => setAccount({ ...account, terms_accepted: e.target.checked })}
                className="mt-0.5 h-4 w-4 accent-primary"
              />
              <span>
                أوافق على{" "}
                <Link href="/terms" className="text-primary-ink underline">
                  الشروط والأحكام
                </Link>
                .
              </span>
            </label>
            <FieldError id="terms_accepted" message={errors.terms_accepted} />
          </div>

          <Button type="submit" variant="accent" size="lg" fullWidth loading={loading}>التالي</Button>
        </form>
      )}

      {step === 2 && (
        <form onSubmit={submitStepTwo} noValidate className="space-y-6">
          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-ink">المواد التي تدرّسها</legend>
            <div className="flex flex-wrap gap-2">
              {subjects.map((subject) => (
                <label
                  key={subject.slug}
                  className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                    professional.subjects.includes(subject.slug)
                      ? "border-primary bg-primary-soft text-primary-ink"
                      : "border-line text-ink-muted"
                  }`}
                >
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={professional.subjects.includes(subject.slug)}
                    onChange={() => toggle("subjects", subject.slug)}
                  />
                  {subject.name_ar}
                </label>
              ))}
            </div>
            <FieldError id="subjects" message={errors.subjects} />
          </fieldset>

          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-ink">المراحل الدراسية</legend>
            <div className="flex flex-wrap gap-2">
              {gradeLevels.map((level) => (
                <label
                  key={level.slug}
                  className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                    professional.grade_levels.includes(level.slug)
                      ? "border-primary bg-primary-soft text-primary-ink"
                      : "border-line text-ink-muted"
                  }`}
                >
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={professional.grade_levels.includes(level.slug)}
                    onChange={() => toggle("grade_levels", level.slug)}
                  />
                  {level.name_ar}
                </label>
              ))}
            </div>
            <FieldError id="grade_levels" message={errors.grade_levels} />
          </fieldset>

          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-ink">لغات التدريس</legend>
            <div className="flex flex-wrap gap-2">
              {LANGUAGES.map((language) => (
                <label
                  key={language.value}
                  className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                    professional.teaching_languages.includes(language.value)
                      ? "border-primary bg-primary-soft text-primary-ink"
                      : "border-line text-ink-muted"
                  }`}
                >
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={professional.teaching_languages.includes(language.value)}
                    onChange={() => toggle("teaching_languages", language.value)}
                  />
                  {language.label}
                </label>
              ))}
            </div>
          </fieldset>

          <div>
            <label htmlFor="t-headline" className="mb-1 block text-sm font-medium text-ink">
              العنوان التعريفي
            </label>
            <input
              id="t-headline"
              value={professional.headline}
              onChange={(e) => setProfessional({ ...professional, headline: e.target.value })}
              placeholder="مدرّس رياضيات للمرحلة الثانوية"
              required
              className={FIELD}
            />
            <FieldError id="t-headline" message={errors.headline} />
          </div>

          <div>
            <label htmlFor="t-years" className="mb-1 block text-sm font-medium text-ink">
              سنوات الخبرة
            </label>
            <input
              id="t-years"
              type="number"
              min={0}
              max={60}
              value={professional.years_experience}
              onChange={(e) =>
                setProfessional({ ...professional, years_experience: Number(e.target.value) })
              }
              required
              className={FIELD}
            />
            <FieldError id="t-years" message={errors.years_experience} />
          </div>

          <div>
            <label htmlFor="t-quals" className="mb-1 block text-sm font-medium text-ink">
              المؤهلات والشهادات
            </label>
            <textarea
              id="t-quals"
              rows={4}
              value={professional.qualifications}
              onChange={(e) =>
                setProfessional({ ...professional, qualifications: e.target.value })
              }
              placeholder="مؤهل في كل سطر"
              className={FIELD}
            />
          </div>

          <div>
            <label htmlFor="t-bio" className="mb-1 block text-sm font-medium text-ink">
              نبذة عنك
            </label>
            <textarea
              id="t-bio"
              rows={5}
              value={professional.bio}
              onChange={(e) => setProfessional({ ...professional, bio: e.target.value })}
              className={FIELD}
            />
          </div>

          <Button type="submit" variant="accent" size="lg" fullWidth loading={loading}>التالي</Button>
        </form>
      )}

      {step === 3 && (
        <form onSubmit={submitStepThree} noValidate className="space-y-6">
          <div className="rounded-2xl border border-line p-5">
            <h2 className="mb-3 text-base font-bold text-ink">المستندات المطلوبة للتحقق</h2>
            <ul className="mb-4 list-inside list-disc space-y-1.5 text-sm text-ink-muted">
              <li>صورة من الهوية أو جواز السفر</li>
              <li>الشهادة الجامعية أو ما يعادلها</li>
              <li>شهادات الخبرة أو التدريب إن وُجدت</li>
            </ul>

            {/* FR-071: nothing is uploaded here, and the endpoint rejects a file
                payload outright. Verification happens through a separate secure
                channel once that feature has its own security review. */}
            <p className="rounded-xl bg-primary-soft p-3 text-sm text-primary-ink">
              لا تُرفع أي مستندات في هذه الخطوة. سيتواصل معك فريقنا عبر قناة آمنة لطلب
              المستندات بعد المراجعة الأولية.
            </p>
          </div>

          <div>
            <label className="flex items-start gap-3 text-sm text-ink">
              <input
                type="checkbox"
                checked={acknowledged}
                onChange={(e) => setAcknowledged(e.target.checked)}
                className="mt-0.5 h-4 w-4 accent-primary"
              />
              أقرّ بأنني أملك المستندات أعلاه وأستطيع تقديمها عند الطلب.
            </label>
            <FieldError id="documents_acknowledged" message={errors.documents_acknowledged} />
            <FieldError id="documents" message={errors.documents} />
          </div>

          <Button type="submit" variant="accent" size="lg" fullWidth loading={loading}>التالي</Button>
        </form>
      )}

      {step === 4 && (
        <form onSubmit={submitStepFour} noValidate className="space-y-6">
          <div>
            <label htmlFor="t-rate" className="mb-1 block text-sm font-medium text-ink">
              السعر لكل حصة (ر.ق)
            </label>
            <input
              id="t-rate"
              type="number"
              min={0}
              step="0.01"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              required
              className={FIELD}
            />
            <FieldError id="t-rate" message={errors.hourly_rate} />
          </div>

          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-ink">التوفّر الأسبوعي</legend>

            <ul className="space-y-3">
              {slots.map((slot, index) => (
                <li key={index} className="flex flex-wrap items-end gap-2">
                  <label className="flex-1">
                    <span className="sr-only">اليوم</span>
                    <select
                      value={slot.day_of_week}
                      onChange={(e) =>
                        setSlots(slots.map((s, i) =>
                          i === index ? { ...s, day_of_week: Number(e.target.value) } : s,
                        ))
                      }
                      className={FIELD}
                    >
                      {DAYS.map((day, dayIndex) => (
                        <option key={day} value={dayIndex}>
                          {day}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label>
                    <span className="sr-only">من</span>
                    <input
                      type="time"
                      value={slot.start_time}
                      onChange={(e) =>
                        setSlots(slots.map((s, i) =>
                          i === index ? { ...s, start_time: e.target.value } : s,
                        ))
                      }
                      className={FIELD}
                    />
                  </label>

                  <label>
                    <span className="sr-only">إلى</span>
                    <input
                      type="time"
                      value={slot.end_time}
                      onChange={(e) =>
                        setSlots(slots.map((s, i) =>
                          i === index ? { ...s, end_time: e.target.value } : s,
                        ))
                      }
                      className={FIELD}
                    />
                  </label>

                  {slots.length > 1 && (
                    <button
                      type="button"
                      onClick={() => setSlots(slots.filter((_, i) => i !== index))}
                      className="rounded-xl border border-line px-3 py-2.5 text-sm text-danger-ink"
                    >
                      حذف
                      <span className="sr-only"> فترة {DAYS[slot.day_of_week]}</span>
                    </button>
                  )}
                </li>
              ))}
            </ul>

            <button
              type="button"
              onClick={() =>
                setSlots([...slots, { day_of_week: 1, start_time: "16:00", end_time: "18:00" }])
              }
              className="mt-3 text-sm font-semibold text-primary-ink underline"
            >
              إضافة فترة
            </button>

            <FieldError id="availability" message={errors.availability} />
          </fieldset>

          <Button type="submit" variant="accent" size="lg" fullWidth loading={loading} loadingLabel="جارٍ إرسال الطلب…">
            إرسال الطلب للمراجعة
          </Button>
        </form>
      )}

      {step > 1 && (
        <button
          type="button"
          onClick={() => setStep(step - 1)}
          className="mt-4 text-sm text-ink-muted underline"
        >
          رجوع للخطوة السابقة
        </button>
      )}
    </div>
  );
}
