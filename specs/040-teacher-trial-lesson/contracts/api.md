# API Contracts: الحصة التجريبية لكل كورس

كل المسارات تحت `/api/v1`.

## 1. التعليم والإلغاء: `PUT /courses/{course}/trial-lesson` (Courses)

- **Auth**: `auth:sanctum`. **Policy**: `CoursePolicy::chooseTrialLesson`.
- **Body**:
  - للتعليم: `{ "lesson": "<uuid>" }`.
  - للإلغاء: `{ "lesson": null, "replacing": "<uuid>" }`.
- **200**: `{ "data": { "trial_lesson": { "uuid", "title", "kind" } | null, "trial_status": "visible"|"unpublished"|"processing"|"unavailable"|null } }`
- **403**: `{ "message": "الحصة التجريبية يختارها مدرّس الكورس." }`، للمساعد ولمن لا يقرّر السعر.
- **422**: `{ "message", "errors": { "lesson": [...] } }` بأسباب data-model.

## 2. السوق (قائمة)

- **كارت الكورس** (`/marketplace/courses`، و`courses` داخل حمولة المدرّس): `+ has_trial`.
- **تفاصيل الكورس** (`/marketplace/courses/{key}`): `+ trial`.
- **تفاصيل المدرّس** (`/marketplace/teachers/{key}`): `trial_lessons` بدل `trial_lesson`.

## 3. الحصة: `GET /marketplace/courses/{courseKey}/trial` (Marketplace)

- **Auth**: لا يوجد. **Middleware**: `throttle:trial-playback`، و**بلا** `throttle:api`.
- **Cache-Control**: `no-store`.
- **200** للمضمَّن:

```json
{ "data": { "uuid": "...", "title": "...", "kind": "embed", "duration_seconds": 1200,
            "embed_url": "https://www.youtube-nocookie.com/embed/...",
            "course": { "uuid": "...", "title": "...", "slug": "..." } } }
```

- **200** للمرفوع: `kind: "video"`، و**بدل** `embed_url`:

```json
"playback": { "manifest_url": "/api/v1/marketplace/courses/{courseKey}/trial/stream",
              "format": "hls", "reload_after_seconds": 400, "duration_seconds": 1800 }
```

- لا `grant`، ولا `watermark`، ولا `renew_after_seconds`، ولا `resume_at_seconds`، ولا `captions`.
- **404** `{ "message": "غير متاح" }`، بنصّ ثابت لكل الأسباب.

## 4. البثّ: `GET /marketplace/courses/{courseKey}/trial/stream`

- **Auth**: لا يوجد. **Middleware**: كما في (3).
- **302**: إلى `https://{zone}.b-cdn.net/bcdn_token=…&token_path=/{videoId}/&expires=…/{videoId}/playlist.m3u8`، صلاحيته `media.trial_link_ttl_seconds`، مع `Cache-Control: no-store`.
- **404**: كما في (3)، ويشمل الحصة المضمَّنة.
- **لا آثار جانبية**: لا صفّ، ولا حدث، ولا تقدّم.

## 5. المحرّر (Courses)

- `CourseResource`: `+ can_choose_trial`، و`trial_status`، و`trial_lesson_uuid`.
- `LessonResource` (على `make()` فقط): `+ is_trial`، و`trial_refusal`.
