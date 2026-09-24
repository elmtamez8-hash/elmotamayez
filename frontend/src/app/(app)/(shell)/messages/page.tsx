"use client";

import { useAuth } from "@/lib/auth-context";
import { P, can } from "@/lib/permissions";

/**
 * The empty pane beside the list (spec 010 · `FR-054`).
 *
 * ⚠️ THE LIST ITSELF MOVED TO `layout.tsx` AND THIS ROUTE STAYED. On a phone the
 * layout renders the sidebar full-width and hides this pane entirely, so what is
 * written here is only ever read on a wide screen — which is why it says «choose
 * a conversation» rather than anything a phone user would find puzzling next to
 * a list they are already looking at.
 *
 * ⚠️ NO «NEW CONVERSATION» BUTTON, AND THE ABSENCE IS STILL THE DESIGN. A private
 * conversation is opened from the teacher's own page — the one place the student
 * has already chosen who they mean — and there is exactly one per teacher. A
 * picker here would be a second directory of teachers, and it would have to
 * answer «which of these may I write to» in a second voice.
 */
export default function MessagesEmptyPane() {
  const { user } = useAuth();

  /*
   * ⚠️ TWO AUDIENCES, ONE SCREEN. The sentence below used to be the student's
   * alone, so a teacher opening their inbox was told to message «مدرّسيك» from
   * «صفحة المدرّس». The teacher's side is decided by `chat.reply` — the same
   * name `ConversationPolicy::teacherSide()` asks and the one «راسِل» is offered
   * on — and a student holds no permissions at all, so it cannot misfire the
   * other way. (Once `teachesOnPlatform` lands on main it is the natural
   * spelling for «does this account teach»; this one asks «may it answer here».)
   */
  const teaches = can(user, P.chatReply);

  return (
    <div className="grid flex-1 place-items-center p-8 text-center">
      <div className="max-w-sm space-y-2">
        <h1 className="text-lg font-semibold text-ink">اختر محادثة</h1>
        {teaches ? (
          <p className="text-sm text-ink-muted">
            محادثاتك الخاصّة مع طلابك. تبقى المحادثة مقروءة بعد انتهاء دراسة الطالب،
            ويتوقّف الإرسال فيها. ولبدء محادثةٍ جديدةٍ اضغط «راسِل» بجوار اسم الطالب
            في قائمة طلاب المجموعة أو في أرصدة الطلاب.
          </p>
        ) : (
          <p className="text-sm text-ink-muted">
            محادثاتك الخاصّة مع مدرّسيك ومن يعاونهم. تبقى المحادثة مقروءة بعد انتهاء دراستك،
            ويتوقّف الإرسال فيها. وتُفتح محادثةٌ جديدةٌ من صفحة المدرّس.
          </p>
        )}
      </div>
    </div>
  );
}
