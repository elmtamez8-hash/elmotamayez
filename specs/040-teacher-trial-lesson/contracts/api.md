# API Contracts: الحصة التجريبية للمدرّس

كل المسارات تحت `/api/v1`.

## 1. اختيار الحصة: `PUT /teacher/trial-lesson`

- **Auth**: `auth:sanctum`، والمستخدم صاحب ملف مدرّس.
- **Body**: `{ "lesson": "<uuid>" | null }`
- **200**: `{ "data": { "trial_lesson": <TrialLesson|null>, "status": "visible"|"unpublished"|"processing"|null } }`
- **403**: الفاعل ليس صاحب ملف مدرّس.
- **422**: `{ "message": "<سبب عربي>", "errors": { "lesson": ["<سبب>"] } }`. الأسباب في data-model.md.

## 2. صفحة المدرّس: `GET /marketplace/teachers/{key}` (قائم)

- `trial_lesson`: الشكل الجديد في data-model.md، أو `null`. يقرأ العلامة الصريحة فقط (FR-016).
- `intro_video_url`: قائم، بلا تغيير.

## 3. وصف تشغيل الحصة المرفوعة: `GET /marketplace/teachers/{key}/trial/playback`

- **Auth**: لا يوجد. **Limiter**: `throttle:trial-playback`.
- **200** (الحصة الحالية `kind = video` وصالحة):

```json
{
  "data": {
    "manifest_url": "/api/v1/marketplace/teachers/{key}/trial/stream",
    "format": "hls" | "progressive",
    "reload_after_seconds": 400 | null,
    "duration_seconds": 1800 | null,
    "captions": [],
    "renditions": []
  }
}
```

  - `manifest_url` مسارنا دائماً، لا رابط Bunny.
  - لا `grant`، ولا `watermark`، ولا `renew_after_seconds`، ولا `resume_at_seconds`.
  - الواجهة تكمل الشكل بقيم محايدة قبل تمريره إلى `VideoPlayer`.
- **404** (ردّ واحد بنصّ ثابت، لكل الأسباب): المدرّس غير معروض، أو بلا حصة، أو الحصة مضمَّنة، أو غير صالحة الآن، أو أصلها غير جاهز. لا يقول السبب، كما يفعل باب الدرس العام اليوم.

## 4. بثّ الحصة المرفوعة: `GET /marketplace/teachers/{key}/trial/stream`

- **Auth**: لا يوجد. **Limiter**: `throttle:trial-playback`.
- **Bunny**: `302` إلى رابط موقَّع صلاحيته `media.trial_link_ttl_seconds`. التوقيع نفسه الذي يُستعمل للطلاب (`token_path`).
- **المحلي**: `200`/`206` بايتات مع `Accept-Ranges: bytes`، و`Cache-Control: no-store`، و`inline` دائماً. لا تنزيل ولو كان الأصل `is_downloadable`.
- **404**: الشروط نفسها التي في (3).
- **لا آثار جانبية**: لا صفّ تصريح، ولا تقدّم، ولا `PlaybackSustained`، ولا سجلّ مرتبط بشخص (FR-011).

## 5. المحرّر: حمولة الدرس للمدرّس (قائمة)

تُضاف: `is_trial: boolean`، و`can_set_trial: boolean`، و`trial_status`.
