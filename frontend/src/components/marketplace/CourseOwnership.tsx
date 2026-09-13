"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";

import { CheckIcon } from "@/components/icons";
import { ApiError } from "@/lib/api";
import { isLearner, useAuth } from "@/lib/auth-context";
import { curriculum, type Curriculum } from "@/lib/curriculum";

/**
 * «هل هذا الكورس لي؟» — تُسأل مرّةً واحدةً للصفحة كلّها.
 *
 * ⛔ THE COURSE PAGE IS A SERVER COMPONENT WITH NO READER, AND THAT IS THE WHOLE
 * REASON THIS EXISTS. It renders from the PUBLIC marketplace endpoint, which
 * answers the same JSON to everyone — so the page could not tell a stranger from
 * somebody who had paid for the course, and showed both the identical screen:
 * a price, a syllabus nothing links to, and one outbound link, to the teacher.
 * A student who owned the course stood on its page with no door into it.
 * (`CoursePrice` is the same shape for the same reason, one field wide.)
 *
 * ⚠️ ONE PROVIDER, NOT A HOOK PER CONSUMER. The rail and the syllabus ask the
 * same question, and a hook each is the same request twice on every page load —
 * for a reader who is usually signed out and usually not enrolled.
 *
 * ⛔ AND IT DECIDES NOTHING. The verdict on every item comes back on the payload
 * (`state`, `lock`), exactly as `/enrollments/{course}` reads it; this file adds
 * no condition of its own. Re-deriving «may they open it» in TypeScript is the
 * two-spellings defect that made a paid-for recording unreachable in 018, and
 * the one this session already paid for once today.
 */
export type CourseOwnership =
  /** Not asked yet, or being asked. The page shows its public face meanwhile. */
  | { state: "unknown" }
  /** Signed out, staff, or a learner with no enrolment in THIS course. */
  | { state: "visitor" }
  | { state: "owner"; data: Curriculum };

const Ctx = createContext<CourseOwnership>({ state: "unknown" });

export function useCourseOwnership(): CourseOwnership {
  return useContext(Ctx);
}

export function CourseOwnershipProvider({
  courseUuid,
  children,
}: {
  courseUuid: string;
  children: ReactNode;
}) {
  const { user, loading } = useAuth();
  const [ownership, setOwnership] = useState<CourseOwnership>({ state: "unknown" });

  useEffect(() => {
    /*
      ⚠️ `loading` IS A THIRD ANSWER AND IT IS NOT «signed out». `useAuth` starts
      every page with `user === null` while it restores the session, so acting on
      that value is how a screen prints «تسجيل دخول» in the face of somebody who
      is signed in — the shape `SiteHeader`'s own docblock warns about.
    */
    if (loading) return;

    if (!isLearner(user)) {
      // A teacher, an assistant, an officer, or nobody. None of them is enrolled
      // here, and asking on their behalf is a request with a known answer.
      setOwnership({ state: "visitor" });

      return;
    }

    let live = true;

    curriculum(courseUuid)
      .then((data) => {
        if (live) setOwnership({ state: "owner", data });
      })
      .catch((error: unknown) => {
        /*
          ⚠️ 403 IS AN ANSWER, NOT A FAILURE. `/courses/{uuid}/curriculum` refuses
          a non-enrolled reader with `code: not_enrolled`, which is exactly the
          question being asked — so it lands on the public face with nothing said.

          Everything else lands there too, and that is a deliberate degradation
          rather than a swallowed error: what the reader gets is the complete,
          correct public page, not a blank one. There is no sentence to show,
          because there is no failed ACTION to explain — nobody pressed anything.
        */
        if (!live) return;

        if (error instanceof ApiError && error.status !== 403 && error.status !== 404) {
          // Kept out of the UI and put where a failure belongs.
          console.warn("[course] ownership probe failed", error.status);
        }

        setOwnership({ state: "visitor" });
      });

    return () => {
      live = false;
    };
  }, [courseUuid, loading, user]);

  return <Ctx.Provider value={ownership}>{children}</Ctx.Provider>;
}

/**
 * «هذا الكورس لك» على الغلاف — الاعتراف قبل أيّ سطر يُقرأ.
 *
 * ⚠️ يعيش هنا لا في ملفٍّ ثالث: هو قارئٌ آخرُ لنفسِ السؤال، وملفٌّ له وحدَه
 * يجعلُ ثلاثةَ مواضعَ تسألُ ما يُسألُ مرّةً.
 */
export function CourseOwnedBadge() {
  const ownership = useCourseOwnership();

  if (ownership.state !== "owner") return null;

  return (
    <span className="absolute start-4 top-4 inline-flex items-center gap-2 rounded-full bg-surface-raised/90 px-3.5 py-1.5 text-xs font-extrabold text-secondary-ink shadow-sm backdrop-blur">
      <CheckIcon />
      هذا الكورس لك
    </span>
  );
}
