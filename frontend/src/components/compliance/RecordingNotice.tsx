import { Alert } from "@/components/ui/Alert";

/**
 * The room says it is being recorded, BEFORE the recording starts (FR-013).
 *
 * ⚠️ IT IS A SURFACE IN THE ROOM, NOT A NOTIFICATION. An announcement delivered
 * to the notification centre is read after the class — by which time the person
 * has already been recorded, and the whole point of announcing it is that they
 * could have chosen not to turn their camera on.
 *
 * ⚠️ AND IT RENDERS BEFORE THE STAGE, NOT BESIDE THE RECORDING INDICATOR. A notice
 * that appears when recording BEGINS is not a notice — the participant is already
 * in the file by the time they read it. This is drawn as soon as the room opens,
 * whether or not the teacher has started recording yet, because "this room is
 * recorded" is a property of the session rather than of the current second.
 *
 * ⚠️ THE WORDING NAMES VOICE AND IMAGE, matching the consent category verbatim.
 * A parent consented to «الظهور في تسجيلات الحصص (‏صوتاً وصورةً)»; a room that then
 * says "this session may be archived" is describing the same thing in words they
 * did not agree to, which is how a consent stops covering what actually happens.
 */
export function RecordingNotice() {
  return (
    <Alert tone="info" title="هذه الحصة تُسجَّل">
      قد يظهر صوتُك وصورتُك في التسجيل، ويصل إلى كلّ من حجز هذه الحصة. إن لم ترغب في
      الظهور، أغلِقِ الكاميرا والميكروفون — ويمكنك المتابعة والمشاركة بالكتابة.
    </Alert>
  );
}
