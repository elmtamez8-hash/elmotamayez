import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import type { Course, PublicListingBlocker } from "@/lib/types";

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
    "ملفّك التدريسي غير معتمد أو غير منشور بعد، والكورس يُعرض في السوق تحت اسم مدرّس معتمد فقط.",
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
  course: Pick<Course, "status" | "slug" | "public_listing" | "can_change_visibility">;
}) {
  const reach = course.public_listing;

  if (reach === undefined || course.status !== "published") return null;

  if (reach.listed) {
    return (
      <Alert tone="success" title="الكورس منشور ويظهر للجميع">
        صفحته العامة:{" "}
        <Link href={`/courses/${course.slug}`} className="font-semibold underline">
          <bdi>/courses/{course.slug}</bdi>
        </Link>
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
