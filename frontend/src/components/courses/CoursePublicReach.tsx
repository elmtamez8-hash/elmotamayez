"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { api } from "@/lib/api";
import type { Course, PublicListingBlocker } from "@/lib/types";

/** The public page's verdict: `true`/`false`, or `null` while unknown (unread, failed). */
function useEnrolmentOpen(slug: string | null): boolean | null {
  const [open, setOpen] = useState<boolean | null>(null);

  useEffect(() => {
    if (slug === null) return;

    let live = true;
    api
      .get<{ data: { enrolment_open: boolean; free_enrollment: boolean } }>(
        `/marketplace/courses/${encodeURIComponent(slug)}`,
      )
      .then((res) => live && setOpen(res.data.enrolment_open || res.data.free_enrollment))
      // Unknown says nothing — never «nobody can buy it» on a failed read.
      .catch(() => live && setOpen(null));

    return () => {
      live = false;
    };
  }, [slug]);

  return open;
}

/**
 * «هل يصلُ الناسُ إلى هذا الكورس؟» — للمدرّسِ، على صفحتَي كورسِه.
 *
 * ⛔ REPORTED 2026-09-26. A course published with `visibility = private`, or in a
 * workspace that is not in the marketplace, answers 404 on `/courses/{slug}` and
 * on `/subscribe` — «العنصر المطلوب غير موجود» to every visitor — while the
 * teacher who has just pressed «انشر الكورس» saw nothing at all: no message, no
 * status, no hint. The server now says WHY (`public_listing.blockers`, the same
 * conditions as `Course::isPubliclyListed()`); this file owns the words.
 *
 * ⚠️ A DRAFT IS NOT A PROBLEM TO WARN ABOUT. Every course starts as one, and the
 * status badge beside the title already says «مسودّة»; the Alert is for a course
 * the teacher BELIEVES is out there and is not. So it speaks only once the
 * course is published — or when a published-looking state hides behind another
 * reason.
 *
 * ⚠️ AND AN ABSENT `public_listing` SAYS NOTHING. The key is sent to the course's
 * editor only; a reader who is not told must not be shown «reachable».
 */
const REASONS: Record<Exclude<PublicListingBlocker, "draft" | "archived" | "private">, string> = {
  workspace_not_in_marketplace:
    "أنت غير معروض في السوق حالياً، فلا يظهر فيه أيّ من كورساتك. إدارة المنصّة هي من تعيد عرضك في السوق — تواصل معها.",
  teacher_not_listed:
    "ملفّ مدرّس هذا الكورس غير معتمد أو غير منشور بعد، والكورس يُعرض في السوق تحت اسم مدرّس معتمد فقط. والمدرّس الذي انضمّ بدعوة إلى مكان غيره لا يملك ملفّاً تدريسياً حتى الآن، فلا تظهر كورساته في السوق.",
};

/*
 * ⚠️ «PRIVATE» HAS TWO ANSWERS, AND THE SERVER DECIDES WHICH ONE A READER GETS.
 * Switching a course between public and private is the course's teacher's call
 * alone (owner decision 2026-09-26): an assistant edits the rest, and the edit
 * page hides the «ظهور الكورس» field from them on `can_change_visibility`. This
 * sentence used to tell every reader to go and pick «عام» there — sending an
 * assistant to a control their screen does not have. Only an explicit `true`
 * earns the instruction; absent or false is told who can do it instead.
 */
const PRIVATE_FOR_TEACHER =
  "ظهور الكورس «خاص»: يصل إليه طلابك المسجّلون فقط، ولا يظهر في السوق ولا تفتح صفحته العامة لأحد. لتجعله عامّاً اختر «عام» في «ظهور الكورس» من صفحة تعديل الكورس.";
const PRIVATE_FOR_OTHERS =
  "ظهور الكورس «خاص»: يصل إليه الطلاب المسجّلون فقط، ولا يظهر في السوق ولا تفتح صفحته العامة لأحد. اطلب من مدرّس الكورس جعله عامًّا.";

export function CoursePublicReach({
  course,
}: {
  course: Pick<Course, "status" | "slug" | "public_listing" | "can_change_visibility" | "can_change_pricing">;
}) {
  const reach = course.public_listing;

  const listed = reach !== undefined && course.status === "published" && reach.listed;
  const open = useEnrolmentOpen(listed ? course.slug : null);

  if (reach === undefined || course.status !== "published") return null;

  if (reach.listed) {
    const page = (
      <Link href={`/courses/${course.slug}`} className="font-semibold underline">
        <bdi>/courses/{course.slug}</bdi>
      </Link>
    );

    /*
     * ⚠️ «VISIBLE» IS NOT «BUYABLE» (journey audit 2026-10-21). A new teacher
     * published, read «يظهر للجميع», and never learnt that every student saw
     * «لم يفتح المدرّس الاشتراك بعد» — no group open, no priced plan. The
     * verdict is the public page's own `enrolment_open`/`free_enrollment`, read
     * back rather than re-derived here.
     */
    if (open === false) {
      return (
        <Alert tone="warning" title="الكورس منشور، لكن لا يستطيع أحد الاشتراك فيه بعد">
          <p>
            يرى الزوّار صفحته العامة ({page}) وعليها «لم يفتح المدرّس الاشتراك بعد»: لا مجموعة مفتوحة له،
            ولا باقة مسعّرة تغطّيه.
          </p>
          {/* The price is the teacher's call; an assistant was told to add a
              plan they cannot reach (assistant journey 2026-10-21). */}
          {course.can_change_pricing === false ? (
            <p className="mt-1">اطلب من مدرّس الكورس إضافة باقة له أو جعله مجانياً.</p>
          ) : (
            <p className="mt-1">
              أضف باقة من{" "}
              <Link href="/manage/plans" className="font-semibold underline">
                «باقات الاشتراك»
              </Link>{" "}
              وتُعرض للطلاب حين تحدّد المنصّة سعرها، أو اجعله مجانياً من تعديل الكورس.
            </p>
          )}
        </Alert>
      );
    }

    return (
      <Alert tone="success" title="الكورس منشور ويظهر للجميع">
        صفحته العامة: {page}
      </Alert>
    );
  }

  const reasons = reach.blockers.flatMap((blocker) => {
    if (blocker === "private") {
      return [course.can_change_visibility === true ? PRIVATE_FOR_TEACHER : PRIVATE_FOR_OTHERS];
    }

    return blocker in REASONS ? [REASONS[blocker as keyof typeof REASONS]] : [];
  });

  return (
    <Alert tone="warning" title="الكورس منشور لكنه لا يظهر للزوّار">
      <p>صفحته العامة وصفحة الاشتراك فيه تجيبان «غير موجود» لكل من ليس مسجّلاً فيه، للأسباب التالية:</p>
      <ul className="mt-2 list-disc space-y-1 ps-5">
        {reasons.map((reason) => (
          <li key={reason}>{reason}</li>
        ))}
      </ul>
    </Alert>
  );
}
