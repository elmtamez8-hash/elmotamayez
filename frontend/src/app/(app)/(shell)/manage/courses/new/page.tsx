"use client";

import { useEffect, useState, type ComponentType, type ReactNode } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { COURSE_TYPES, type CourseType } from "@/lib/labels";
import { CURRENCY } from "@/lib/platform";
import { useRouter } from "next/navigation";
import type { Course } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import {
  CheckIcon,
  CoursesIcon,
  DocumentIcon,
  InfoIcon,
  ListIcon,
  LockIcon,
  PlayIcon,
  SessionsIcon,
  SparkIcon,
  TagIcon,
  UserIcon,
  UsersIcon,
  type IconProps,
} from "@/components/icons";
import { SelectField, TextField, TextareaField } from "@/components/ui/Field";

/*
  The page reads as four short steps with a live summary beside them, rather than
  one long column of inputs: a teacher filling it in sees what the course will
  look like before it exists. Every animation is `banner-rise` or a `motion-safe:`
  transition, so the reduced-motion block in `globals.css` flattens all of it.
*/

const TYPE_ICONS: Record<CourseType, ComponentType<IconProps>> = {
  individual: UserIcon,
  group: UsersIcon,
  recorded: PlayIcon,
};

/** «فردي — حصص خاصّة مع الطالب» → a name and the line that explains it. */
function splitLabel(label: string): [string, string] {
  const [name, ...rest] = label.split(" — ");
  return [name, rest.join(" — ")];
}

function FormSection({
  Icon,
  step,
  title,
  description,
  delay,
  children,
}: {
  Icon: ComponentType<IconProps>;
  step: number;
  title: string;
  description: string;
  delay: number;
  children: ReactNode;
}) {
  return (
    <section
      className="banner-rise group rounded-3xl border border-line bg-surface-raised p-6 transition duration-300 hover:border-primary/30"
      style={{ animationDelay: `${delay}ms` }}
    >
      <header className="mb-5 flex items-start gap-3">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink transition duration-300 motion-safe:group-hover:scale-110 motion-safe:group-hover:-rotate-6">
          <Icon className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-xs font-semibold text-ink-muted">الخطوة {step}</p>
          <h2 className="text-base font-bold text-ink">{title}</h2>
          <p className="mt-0.5 text-sm text-ink-muted">{description}</p>
        </div>
      </header>
      <div className="space-y-4">{children}</div>
    </section>
  );
}

/** A checkbox drawn as a card; the whole card is the label, so it is one target. */
function ToggleTile({
  id,
  Icon,
  title,
  description,
  checked,
  onChange,
}: {
  id: string;
  Icon: ComponentType<IconProps>;
  title: string;
  description: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <label
      htmlFor={id}
      className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition duration-200 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary motion-safe:hover:-translate-y-0.5 ${
        checked
          ? "border-primary bg-primary-soft"
          : "border-line hover:border-primary/40 hover:bg-primary-soft/40"
      }`}
    >
      <input
        id={id}
        name={id}
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="sr-only"
      />
      <span
        className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl transition duration-200 ${
          checked ? "bg-primary text-white" : "bg-surface text-ink-muted"
        }`}
      >
        <Icon className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink">{title}</span>
        <span className="mt-0.5 block text-xs text-ink-muted">{description}</span>
      </span>
      <span
        aria-hidden="true"
        className={`grid h-5 w-5 shrink-0 place-items-center rounded-full border transition duration-200 ${
          checked ? "animate-float-in border-primary bg-primary text-white" : "border-line"
        }`}
      >
        {checked && <CheckIcon className="h-3 w-3" />}
      </span>
    </label>
  );
}

export default function CreateCoursePage() {
  const router = useRouter();

  /*
    ⚠️ REQUIRED SINCE THE DAY `subject_id` WAS FOUND NULL ON EVERY COURSE ON THE
    PLATFORM. The column arrived with 007's pricing migration and was fillable
    from that day, so it read as finished — and no request, Action, seeder or
    screen ever wrote it. Everything that groups by subject was grouping nothing.
  */
  const [subjects, setSubjects] = useState<{ uuid: string; label: string }[]>([]);
  /*
    ⚠️ THE STAGE, AND IT HAD NO WRITER AT ALL UNTIL NOW — `subject_id`'s history
    one column along. `courses.grade_level` has been fillable since 006 and is
    read by the settlement-rate key and the course leaderboard, and no request,
    form, Action or seeder ever assigned it: NULL on 95 of 96 rows. Optional
    rather than required — making it mandatory is a product decision nobody has
    taken, and it would refuse every future edit of the 95.
  */
  const [stages, setStages] = useState<{ slug: string; name: string }[]>([]);
  const [form, setForm] = useState({
    title: "",
    description: "",
    currency: CURRENCY,
    is_sequential: true,
    is_free_enrollment: false,
    subject: "",
    grade_level: "",
    /*
      ⚠️ EMPTY, NOT PRE-PICKED. `courses.course_type` has carried a DB default of
      `recorded` since 2026-08-01 with nothing anywhere writing it, so every
      course a teacher made declared itself recorded about a choice nobody took —
      and the student's course page drops its «الحصص» tab on exactly that value.
      A default selected here would be that same unmade decision, moved into the
      browser; the server refuses an empty one.
    */
    course_type: "",
  });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api
      .get<{ data: { uuid: string; label: string }[] }>("/course-subjects")
      .then((response) => setSubjects(response.data ?? []))
      .catch(() => setSubjects([]));
  }, []);

  useEffect(() => {
    api
      .get<{ data: { slug: string; name: string }[] }>("/signup/grade-levels")
      .then((response) => setStages(response.data ?? []))
      .catch(() => setStages([]));
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      const course = await api.post<Course>("/courses", form);
      router.push(`/manage/courses/${course.uuid}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const subjectLabel = subjects.find((s) => s.uuid === form.subject)?.label;
  const stageLabel = stages.find((s) => s.slug === form.grade_level)?.name;
  const chosenType = COURSE_TYPES.find((t) => t.value === form.course_type);
  const TypeIcon = chosenType ? TYPE_ICONS[chosenType.value] : CoursesIcon;

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        Icon={CoursesIcon}
        title="كورس جديد"
        description="أربع خطوات قصيرة، وبعد الإنشاء تضيف الدروس والمجموعات والباقات."
      />

      <form
        onSubmit={submit}
        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]"
      >
        <div className="space-y-6">
          {error && <Alert tone="danger" title={error} />}

          <FormSection
            Icon={DocumentIcon}
            step={1}
            title="بيانات الكورس"
            description="الاسم والوصف اللذان يراهما الطالب أولاً."
            delay={0}
          >
            <TextField
              id="title"
              label="عنوان الكورس"
              value={form.title}
              onChange={(v) => setForm({ ...form, title: v })}
              error={fields.title}
              required
            />

            <TextareaField
              id="description"
              label="الوصف"
              value={form.description}
              onChange={(v) => setForm({ ...form, description: v })}
              error={fields.description}
              hint="اشرح في سطرين ماذا سيتعلّم الطالب."
            />
          </FormSection>

          {/*
            ⚠️ THE SUBJECT DECIDES WHERE THE COURSE IS FOUND. It is what the
            marketplace groups by and what a student's homework and practice
            filters narrow by — a course filed under nothing appears in none of them.
          */}
          <FormSection
            Icon={TagIcon}
            step={2}
            title="التصنيف"
            description="حتى يجد الطالب الكورس في السوق وفي فلاتره."
            delay={80}
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <SelectField
                id="subject"
                label="المادّة"
                value={form.subject}
                onChange={(v) => setForm({ ...form, subject: v })}
                placeholder="اختر المادّة"
                options={subjects.map((subject) => ({ value: subject.uuid, label: subject.label }))}
                error={fields.subject}
                required
              />

              <SelectField
                id="grade_level"
                label="المرحلة الدراسية"
                value={form.grade_level}
                onChange={(v) => setForm({ ...form, grade_level: v })}
                placeholder="بلا مرحلة محدّدة"
                options={stages.map((stage) => ({ value: stage.slug, label: stage.name }))}
                error={fields.grade_level}
                hint="تُستخدم لفلترة كورساتك ولربط سعر التسوية بالمرحلة."
              />
            </div>
          </FormSection>

          {/*
            ⚠️ THE ONE CHOICE WITH NO DEFAULT, AND EACH TILE SAYS WHAT IT DECIDES.
            «جماعي» and «مسجّل» are the pair a teacher picks between wrongly, and
            what separates them is whether the course has live sessions at all —
            which is exactly what the student's course page reads this for.
          */}
          <FormSection
            Icon={SessionsIcon}
            step={3}
            title="نوع الكورس"
            description="يقرّر أين يظهر الكورس في السوق، وهل تظهر للطالب حصصه ومواعيدها."
            delay={160}
          >
            <fieldset>
              <legend className="sr-only">نوع الكورس</legend>
              <div className="grid gap-3 sm:grid-cols-3">
                {COURSE_TYPES.map((type, index) => {
                  const [name, hint] = splitLabel(type.label);
                  const Icon = TYPE_ICONS[type.value];
                  const selected = form.course_type === type.value;

                  return (
                    <label
                      key={type.value}
                      className={`relative flex cursor-pointer flex-col gap-3 rounded-2xl border p-4 transition duration-200 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary motion-safe:hover:-translate-y-1 ${
                        selected
                          ? "border-primary bg-primary-soft"
                          : "border-line hover:border-primary/40 hover:bg-primary-soft/40"
                      }`}
                    >
                      <input
                        type="radio"
                        name="course_type"
                        value={type.value}
                        checked={selected}
                        onChange={() => setForm({ ...form, course_type: type.value })}
                        required={index === 0}
                        className="sr-only"
                      />
                      <span
                        className={`grid h-10 w-10 place-items-center rounded-xl transition duration-200 ${
                          selected ? "bg-primary text-white" : "bg-surface text-primary-ink"
                        }`}
                      >
                        <Icon className="h-5 w-5" />
                      </span>
                      <span>
                        <span className="block text-sm font-bold text-ink">{name}</span>
                        <span className="mt-1 block text-xs leading-relaxed text-ink-muted">{hint}</span>
                      </span>
                      {selected && (
                        <span
                          aria-hidden="true"
                          className="animate-float-in absolute end-3 top-3 grid h-5 w-5 place-items-center rounded-full bg-primary text-white"
                        >
                          <CheckIcon className="h-3 w-3" />
                        </span>
                      )}
                    </label>
                  );
                })}
              </div>
              {fields.course_type && (
                <p role="alert" className="mt-2 text-sm text-danger-ink">
                  {fields.course_type}
                </p>
              )}
            </fieldset>
          </FormSection>

          {/*
            ⛔ NO PRICE FIELD (owner decision 2026-09-25): a course is sold through
            a plan and nothing else, so a one-off price here would price nothing a
            student can buy. The column stays; this screen no longer writes it, and
            an edit leaves an existing value untouched because the key is not sent.

            ⛔ «مجاني» is the teacher's decision and nothing else (owner decision
            2026-09-25). Unticked, the course is entered through a plan only; with
            no plan yet it shows «لم يفتح المدرّس الاشتراك بعد» — never «free».
          */}
          <FormSection
            Icon={LockIcon}
            step={4}
            title="الدخول والترتيب"
            description="كيف يدخل الطالب الكورس، وبأي ترتيب يفتح الدروس."
            delay={240}
          >
            <div className="grid gap-3 sm:grid-cols-2">
              <ToggleTile
                id="is_free_enrollment"
                Icon={SparkIcon}
                title="كورس مجاني"
                description="يسجّل فيه أي طالب بلا دفع ولا باقة."
                checked={form.is_free_enrollment}
                onChange={(v) => setForm({ ...form, is_free_enrollment: v })}
              />
              <ToggleTile
                id="is_sequential"
                Icon={ListIcon}
                title="تسلسل إجباري"
                description="لا يفتح الدرس التالي قبل إتمام السابق."
                checked={form.is_sequential}
                onChange={(v) => setForm({ ...form, is_sequential: v })}
              />
            </div>
          </FormSection>
        </div>

        <aside
          className="banner-rise space-y-4 lg:sticky lg:top-24"
          style={{ animationDelay: "120ms" }}
        >
          <div className="rounded-3xl border border-line bg-surface-raised p-5">
            <p className="mb-4 text-xs font-semibold text-ink-muted">معاينة الكورس</p>

            <div className="flex items-start gap-3">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-white">
                <TypeIcon className="h-6 w-6" />
              </span>
              <div className="min-w-0">
                <p
                  className={`break-words text-base font-bold ${
                    form.title ? "text-ink" : "text-ink-muted"
                  }`}
                >
                  {form.title || "عنوان الكورس"}
                </p>
                <p className="mt-0.5 text-sm text-ink-muted">
                  {[subjectLabel, stageLabel].filter(Boolean).join(" · ") || "لم تُحدَّد المادّة بعد"}
                </p>
              </div>
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              {chosenType && <Badge tone="info">{splitLabel(chosenType.label)[0]}</Badge>}
              <Badge tone={form.is_free_enrollment ? "success" : "neutral"}>
                {form.is_free_enrollment ? "مجاني" : "بالباقات"}
              </Badge>
              {form.is_sequential && <Badge tone="neutral">تسلسل إجباري</Badge>}
            </div>

            <div className="mt-5 space-y-2">
              <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الإنشاء…">
                أنشئ الكورس
              </Button>
              <Button variant="secondary" fullWidth onClick={() => router.back()}>
                إلغاء
              </Button>
            </div>
          </div>

          <div className="flex items-start gap-2 rounded-2xl bg-primary-soft/60 p-4 text-xs leading-relaxed text-primary-ink">
            <InfoIcon className="mt-0.5 h-4 w-4 shrink-0" />
            <p>
              بعد الإنشاء تفتح صفحة الكورس لتضيف الدروس، ثم المجموعات والباقات التي يشترك بها
              الطلاب.
            </p>
          </div>
        </aside>
      </form>
    </div>
  );
}
