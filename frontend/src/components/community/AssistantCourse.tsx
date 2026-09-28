import { BookIcon } from "@/components/icons";
import { StatusBadge } from "@/components/ui/Badge";
import type { AssistantCourse } from "@/lib/assistants";

/**
 * A course's face on the team screen: its cover, or the book glyph when it has
 * none. `aria-hidden` either way — the title beside it is the text.
 *
 * ⚠️ A PLAIN `<img>`, for the reason `Avatar` gives: `next/image` would put every
 * teacher-uploaded cover through `sharp`, whose advisories this repository
 * accepts on the strength of there being no such call site.
 */
export function CourseThumb({ course, size = "md" }: { course: AssistantCourse; size?: "sm" | "md" }) {
  const box = size === "sm" ? "h-6 w-6 rounded-md" : "h-10 w-10 rounded-xl";

  if (course.cover_url) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={course.cover_url}
        alt=""
        aria-hidden="true"
        loading="lazy"
        className={`${box} shrink-0 border border-line object-cover`}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      className={`${box} grid shrink-0 place-items-center bg-primary-soft text-primary-ink`}
    >
      <BookIcon className={size === "sm" ? "h-3.5 w-3.5" : "h-5 w-5"} />
    </span>
  );
}

/**
 * One course on an assistant's card: cover, title, and only the details that
 * say something — the status when it is NOT published (a draft is the surprise
 * worth reading; «منشور» on every chip is noise), and the teacher in an academy.
 */
export function CourseChip({ course, showTeacher }: { course: AssistantCourse; showTeacher: boolean }) {
  return (
    <li className="flex max-w-full items-center gap-2 rounded-2xl border border-line bg-surface py-1 ps-1 pe-3 text-xs text-ink">
      <CourseThumb course={course} size="sm" />
      <span className="min-w-0 truncate font-medium">{course.title}</span>
      {showTeacher && course.teacher && (
        <span className="shrink-0 text-ink-muted">· {course.teacher.name}</span>
      )}
      {course.status !== undefined && course.status !== "published" && (
        <StatusBadge status={course.status} />
      )}
    </li>
  );
}
