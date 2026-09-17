"use client";

import Link from "next/link";
import type { ComponentType } from "react";

import { CurriculumTree } from "@/components/courses/CurriculumTree";
import {
  AssignmentIcon,
  AudioIcon,
  ChevronStartIcon,
  ClockIcon,
  DocumentIcon,
  DownloadIcon,
  ExamIcon,
  ExternalLinkIcon,
  InfoIcon,
  LockIcon,
  PlayIcon,
  SessionsIcon,
  SparkIcon,
} from "@/components/icons";
import { useCourseOwnership } from "@/components/marketplace/CourseOwnership";
import { counted, lessonTypeLabel } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import type { CurriculumSection } from "@/lib/public-api";

/**
 * The published tree, as a visitor who has not bought the course may read it —
 * and, for somebody who HAS, their own tree instead.
 *
 * ⚠️ THIS IS THE ONE PLACE A LESSON LINK IS MADE FOR A VISITOR, AND ITS CONDITION
 * IS THE PAYLOAD'S SHAPE — NOT A RULE RESTATED HERE (023 · FR-005/SC-004,
 * amended by 032 · FR-019).
 *
 * The rule used to be «nothing here is a link», and the guard was that the
 * payload carried no identifier at all. It still carries none for every item
 * EXCEPT an open embedded lesson: that one has no media asset, so its uuid opens
 * nothing at the playback endpoint, which is why 032 publishes it and nothing
 * else. The guard is unchanged in kind — a link cannot be built for any other
 * item because there is no `uuid` on it to build one from.
 *
 * ⛔ DO NOT RE-DERIVE THE CONDITION HERE. Writing `kind === "embed" && …` in
 * TypeScript is a second spelling of `Lesson::isPubliclyReadable()`, and the two
 * drift: the version that made a paid-for recording unreachable in 018 was
 * exactly that. Read the key the server filled.
 *
 * ⛔ AND THE OWNER'S BRANCH BUILDS NO LINKS OF ITS OWN EITHER. It hands the
 * authenticated curriculum to `CurriculumTree` — the SAME component
 * `/enrollments/{course}` renders — so the locks, the reasons and the addresses
 * are the ones the server decided, in one implementation. A second syllabus
 * renderer here would be the two-spellings defect arriving through the door
 * built to close it.
 */

/** The icon per item kind, and the family its tint belongs to. */
const KINDS: Record<string, { Icon: ComponentType; tone: string }> = {
  // ما يُشاهَد أو يُسمَع.
  video: { Icon: PlayIcon, tone: "bg-primary-soft text-primary-ink" },
  embed: { Icon: PlayIcon, tone: "bg-primary-soft text-primary-ink" },
  live_session: { Icon: SessionsIcon, tone: "bg-primary-soft text-primary-ink" },
  audio: { Icon: AudioIcon, tone: "bg-primary-soft text-primary-ink" },

  // ما يُقرَأ أو يُؤخَذ.
  article: { Icon: DocumentIcon, tone: "bg-secondary/15 text-secondary-ink" },
  pdf: { Icon: DocumentIcon, tone: "bg-secondary/15 text-secondary-ink" },
  note: { Icon: InfoIcon, tone: "bg-secondary/15 text-secondary-ink" },
  file: { Icon: DownloadIcon, tone: "bg-secondary/15 text-secondary-ink" },
  link: { Icon: ExternalLinkIcon, tone: "bg-secondary/15 text-secondary-ink" },

  // ما يُقيَّم.
  exam: { Icon: ExamIcon, tone: "bg-danger/12 text-danger-ink" },
  assignment: { Icon: AssignmentIcon, tone: "bg-danger/12 text-danger-ink" },
};

/*
  ⚠️ A KIND THIS MAP HAS NOT HEARD OF STILL DRAWS. `LessonTypeRegistry` on the
  server is what decides the vocabulary, and a screen that renders nothing for a
  type added there would show an empty square beside a real lesson — the
  invisible-state family this tree has shipped four times. The fallback is the
  neutral document mark, and the WORD beside it is `lessonTypeLabel()`'s, which
  falls back to the raw key rather than to silence.
*/
const FALLBACK = { Icon: DocumentIcon, tone: "bg-primary-soft text-primary-ink" } as const;

function duration(seconds: number | null | undefined): string | null {
  if (seconds === null || seconds === undefined || seconds <= 0) return null;

  const minutes = Math.round(seconds / 60);

  return counted(minutes, {
    one: "دقيقة",
    two: "دقيقتان",
    few: "دقائق",
    many: "دقيقة",
    other: "دقيقة",
  });
}

export function CourseCurriculum({
  sections,
  courseSlug,
}: {
  sections: CurriculumSection[];
  /** Absent on a preview that has no page to link to yet. */
  courseSlug?: string;
}) {
  const ownership = useCourseOwnership();

  if (ownership.state === "owner") {
    return <CurriculumTree sections={ownership.data.sections} />;
  }

  return (
    <ol className="flex flex-col gap-5">
      {sections.map((section, sectionIndex) => (
        <li
          key={`${section.title}-${sectionIndex}`}
          className="overflow-hidden rounded-2xl border border-line bg-surface-raised"
        >
          <h3 className="flex items-center gap-3 border-b border-line bg-primary-soft px-5 py-3.5 text-sm font-extrabold text-primary-ink">
            {/*
              الترقيمُ معلومةٌ لا زينة: الوحداتُ تُدرَّسُ بالترتيبِ الذي وضعَها به
              المدرّس.

              ⚠️ لكنّه كانَ يُقرَأُ عدداً لا ترتيباً — قرصٌ مصمتٌ يحملُ رقماً
              لاتينيّاً بجوارِ عنوانٍ إنجليزيّ، في صفحةٍ كلُّ أرقامِها عربيّةٌ
              (٨ دروس · ١٠ دقائق). فالرقمُ يمرُّ على `arabicNumber` — التهجئةُ
              القائمةُ التي تستعملُها `StarRating` و`TrustScoreBadge` — وكلمةُ
              «الوحدة» أمامَه تحسمُ أنّه ترتيبٌ لا كَمّ.
            */}
            <span className="shrink-0 text-xs font-bold opacity-70">
              الوحدة {arabicNumber(sectionIndex + 1)}
            </span>
            <span aria-hidden="true" className="h-3.5 w-px shrink-0 bg-primary-ink/25" />
            <span className="min-w-0 flex-1">{section.title}</span>
          </h3>

          <ol className="flex flex-col">
            {section.chapters.map((chapter, chapterIndex) => (
              <li key={`${chapter.title}-${chapterIndex}`}>
                <h4 className="px-5 pt-4 pb-1 text-xs font-bold text-ink-muted">
                  {chapter.title}
                </h4>

                <ol className="flex flex-col">
                  {chapter.items.map((item, itemIndex) => {
                    const openable =
                      item.is_open === true &&
                      item.uuid !== undefined &&
                      courseSlug !== undefined;

                    /*
                      ⚠️ حالةٌ ثالثةٌ لأنّ الحالاتِ ثلاث، لا لأنّ الشكلَ يحتملُها.
                      «يُفتَحُ الآن» و«مجّانيٌّ ويحتاجُ حساباً» و«بعد الشراء»
                      ثلاثةُ أجوبةٍ مختلفةٍ لمشترٍ يقرّر — وجمعُ الثاني مع الثالثِ
                      في «بعد التسجيل» هو ما كانَ يُخفي على الصفحةِ قرارَ المدرّسِ
                      بفتحِ الدرس.

                      ولا رابطَ هنا: الخادمُ لا يُرسِلُ `uuid` مع هذا المفتاح،
                      فالشرطُ أعلاه لا يتحقّقُ أصلاً ولا شيءَ يُبنى.
                    */
                    const freeWithAccount = !openable && item.free_with_account === true;

                    const kind = KINDS[item.kind] ?? FALLBACK;
                    const length = duration(item.duration_seconds);

                    const body = (
                      <>
                        <span
                          className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl ${kind.tone}`}
                          aria-hidden="true"
                        >
                          <kind.Icon />
                        </span>

                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                          <span className="truncate text-sm font-bold text-ink">
                            {item.title}
                          </span>
                          <span className="text-xs text-ink-muted">
                            {lessonTypeLabel(item.kind)}
                          </span>
                        </span>

                        {length !== null && (
                          <span className="flex shrink-0 items-center gap-1 text-xs text-ink-muted">
                            <span aria-hidden="true">
                              <ClockIcon />
                            </span>
                            <bdi>{length}</bdi>
                          </span>
                        )}

                        {/*
                          ⚠️ الحالة كلمةٌ قبل أن تكون شكلاً. «مجّانيّة» تُقرأ،
                          و«مقفول» تُقرأ — واللون فوقهما تأكيد. أيقونةٌ وحدها
                          حالةٌ لا يصل إليها قارئُ الشاشة.
                        */}
                        {openable ? (
                          <span className="flex shrink-0 items-center gap-1.5 rounded-lg bg-secondary/15 px-2 py-1 text-xs font-bold text-secondary-ink">
                            مجّانيّة
                            <span aria-hidden="true">
                              <ChevronStartIcon />
                            </span>
                          </span>
                        ) : freeWithAccount ? (
                          <span className="flex shrink-0 items-center gap-1.5 rounded-lg bg-primary-soft px-2 py-1 text-xs font-bold text-primary-ink">
                            <span aria-hidden="true">
                              <SparkIcon />
                            </span>
                            مجّانيّة بحساب
                          </span>
                        ) : (
                          <span className="flex shrink-0 items-center gap-1.5 rounded-lg bg-line px-2 py-1 text-xs font-bold text-ink-muted">
                            <span aria-hidden="true">
                              <LockIcon />
                            </span>
                            بعد التسجيل
                          </span>
                        )}
                      </>
                    );

                    return (
                      <li key={`${item.title}-${itemIndex}`}>
                        {openable ? (
                          <Link
                            href={`/courses/${courseSlug}/lessons/${item.uuid}`}
                            className="flex items-center gap-3 px-5 py-3 transition hover:bg-primary-soft/50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                          >
                            {body}
                          </Link>
                        ) : (
                          <span className="flex items-center gap-3 px-5 py-3">{body}</span>
                        )}
                      </li>
                    );
                  })}
                </ol>
              </li>
            ))}
          </ol>
        </li>
      ))}
    </ol>
  );
}
