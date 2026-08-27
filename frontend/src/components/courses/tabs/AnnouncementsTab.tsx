"use client";

import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import type { CourseAnnouncement } from "@/lib/course-hub";
import { formatDateTime } from "@/lib/labels";

/**
 * What was said about this course (US2 · FR-019).
 *
 * ⚠️ THE SCREEN THAT DID NOT EXIST. Until now the whole of a student's
 * announcement surface was the notification bell — which is why the notification
 * body carries the announcement verbatim rather than a link. A bell is read once
 * and cleared; «متى قال إنّ الحصّة تأجّلت؟» had no answer anywhere in the product.
 *
 * ⚠️ AND THE PLATFORM'S NOTICES ARE NOT HERE. A workspace-wide announcement is
 * about every course this teacher teaches; repeated under each course's tab it
 * is the same sentence three times, and FR-019 asks for this course's.
 */
export function AnnouncementsTab({ announcements }: { announcements: CourseAnnouncement[] }) {
  if (announcements.length === 0) {
    return (
      <EmptyState
        title="لا تنبيهات في هذه المادّة"
        description="حين يكتب مدرّسك تنبيهاً عن هذه المادّة ستجده هنا."
      />
    );
  }

  return (
    <div className="space-y-4">
      {announcements.map((announcement, index) => (
        <div
          key={announcement.uuid}
          className="banner-rise"
          style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
        >
          <Card as="article" padding="sm">
            <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
              {/* «عاجل» is a word before it is a colour: a tone alone carries
                  nothing to a reader who cannot separate the two, and Tailwind
                  v4 emits no rule at all for a token `@theme` never defined. */}
              {announcement.is_urgent && <Badge tone="danger">عاجل</Badge>}
              <span>{formatDateTime(announcement.published_at)}</span>
              {announcement.author_name !== null && (
                <>
                  <span aria-hidden>·</span>
                  <span>{announcement.author_name}</span>
                </>
              )}
            </div>

            {/* `whitespace-pre-line`: the teacher's line breaks are the only
                formatting an announcement has, and the body is plain text — it
                is never rendered as markup, so there is no sanitiser to get
                wrong. */}
            <p className="whitespace-pre-line text-sm leading-relaxed text-ink">
              {announcement.body}
            </p>
          </Card>
        </div>
      ))}
    </div>
  );
}
