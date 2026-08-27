# Phase 1 — Contracts: مسارات ٠٢١

**Date**: 2026-08-27 · **Data model**: [../data-model.md](../data-model.md) · **Research**: [../research.md](../research.md)

كلُّ المساراتِ تحتَ `/api/v1` بمجموعةِ `api` ومصادقةِ Sanctum. كلُّ معرّفٍ في المسارِ والحمولةِ **uuid**. كلُّ كتابةٍ خلفَ **محدّدٍ مُسمّى** — السطريُّ `throttle:N,M` ممنوع.

## الجردة — ما يُعادُ استعمالُه قبلَ ما يُخترَع

| # | المسار | الحالة |
|---|---|---|
| **قائمٌ بلا لمسة** | `GET /class-sessions?course=` · `GET /schedule` · `GET /class-sessions/{s}/attendance` | مرشِّحُ `course` قائمٌ في `ClassSessionController@index` |
| **قائمٌ + مرشِّح** | `GET /exams?course=` · `GET /assignments?course=` · `GET /certificates?course=` | الثلاثةُ بلا مرشِّحِ كورسٍ اليوم؛ الأعمدةُ الثلاثةُ موجودة |
| **جديد** | المنهجُ · الحصّةُ القادمةُ للمادّةِ · تنبيهاتُ الطالبِ · المجموعاتُ كلُّها · الخيطُ · القائمة | ١٥ مساراً أدناه |

---

## أ. المنهجُ والرأس

### `GET /courses/{course}/curriculum`
الشجرةُ كلُّها بحالةِ كلِّ عنصر، **بقراءةٍ واحدةٍ مجمَّعة** (FR-009 · SC-004).

```jsonc
{
  "course": {
    "uuid": "…", "title": "الرياضيات — التاسع",
    "cover_url": "http://…/storage/courses/x.jpg",   // ⚠️ إملاءُ PublicCourseCardResource:31 حرفاً بحرف
    "teacher_name": "أ. سامي", "is_sequential": true, "course_type": "group",
    "progress_pct": 40, "completed_count": 8, "countable_count": 20,
    "resume_lesson_uuid": "…"                        // FR-010 — أوّلُ مفتوحٍ غيرِ مكتمل، null إن لا شيء
  },
  "cohort_gate": {                                   // ⚠️ FR-028أ/ب — انظر أدناه
    "required": true, "satisfied": false, "joinable_exists": true,
    "message": "اختر مجموعتَك للبدء."
  },
  "sections": [{
    "uuid": "…", "title": "الجبر", "order": 1,
    "chapters": [{
      "uuid": "…", "title": "المعادلات", "order": 1,
      "lessons": [{
        "uuid": "…", "title": "المعادلاتُ الخطّيّة", "type": "video",
        "asset_kind": "video", "family": "media", "duration_seconds": 480,
        "state": "completed",                        // completed · open · locked
        "lock": null
      }, {
        "uuid": "…", "title": "المتباينات", "type": "video",
        "state": "locked",
        "lock": { "code": "sequence", "message": "أكمِلْ «المعادلاتُ الخطّيّة» أوّلاً — هذا الكورس متسلسل." }
      }]
    }]
  }]
}
```

**`lock.code` ∈** `sequence` · `exam_attempt` · `exam_pass` · `no_seat` · `inactive` · `no_cohort` — ⚠️ **ثوابتُ `LessonAccess` القائمةُ حرفيّاً** زائدَ `no_cohort` الجديدة. الحالةُ والسببُ يُبنيان في `LessonGate::forTree()` فوقَ نفسِ المنطقِ الذي يُجيبُ عن درسٍ واحد — **لا حسابَ ثانٍ** (FR-008 · قيدُ السبيك).

⚠️ **`not_visible` و`not_enrolled` لا تظهران هنا أبداً**: الأوّلُ يُحذَفُ الصفَّ من الشجرةِ أصلاً (FR-004)، والثاني يجعلُ الطلبَ كلَّه `403`.

⚠️ **`cohort_gate.satisfied = false` مع `joinable_exists = false` ⇒ الشجرةُ مفتوحة.** هذا هو الصمّام: لا `lock` بـ`no_cohort` على صفٍّ واحدٍ عندئذٍ. شرطٌ لا فعلَ يُحقِّقُه قفلٌ دائمٌ على محتوًى مدفوع.

### `GET /courses/{course}/next-session`
`200` بالحصّةِ أو `{ "session": null }`. مقصورةٌ على مادّةٍ واحدةٍ ومجموعةِ القارئِ فيها — `/schedule/next` القائمُ يقرأُ حجوزاتِ الطالبِ عبرَ المساحاتِ كلِّها وهو الشكلُ الخطأ للرأس.

```jsonc
{ "session": { "uuid": "…", "title": "…", "starts_at": "…", "ends_at": "…",
               "status": "scheduled", "room_closed": false,
               "join_open": false,                    // ⚠️ نافذةُ الدخولِ + حالةُ الغرفة، من الخادمِ لا من ساعةِ المتصفّح
               "has_seat": true, "seats_left": 3 } }
```

### `GET /courses/{course}/announcements`
⚠️ **جديدٌ بالكامل**: لا مسارَ للطالبِ اليوم — `/manage/announcements` للمدرّسِ وحدَه، والتنبيهُ يبلغُ الطالبَ عبرَ صفوفِ `notifications` لا غير. يُرجِعُ تنبيهاتِ المادّةِ **ومجموعةِ القارئِ فيها** وحدَها (FR-019 · FR-019ب)، المنشورَ غيرَ المخفيِّ فقط.

---

## ب. المجموعات — قراءةُ الطالب

| المسار | الجواب |
|---|---|
| `GET /courses/{course}/cohorts` | المجموعاتُ المتاحةُ للانضمام + عضويّةُ القارئِ الحاليّةُ إن وُجِدت + طلبُه المعلَّقُ إن وُجِد |
| `GET /cohorts/{cohort}/roster` | قائمةُ الزملاء — R14 |
| `GET /cohorts/{cohort}/chat` | الخيطُ (uuid + `locked_at` + منعُ القارئِ إن وُجِد) — على نمطِ `/class-sessions/{s}/chat` |

```jsonc
// GET /courses/{course}/cohorts
{
  "membership": { "cohort_uuid": "…", "cohort_name": "السبت ٤م", "joined_at": "…" },
  "pending_request": { "uuid": "…", "to_cohort_name": "الأحد ٦م", "created_at": "…" },
  "cohorts": [{
    "uuid": "…", "name": "الأحد ٦م", "description": "…",
    "status": "open", "seats_left": 3, "is_full": false,   // ⚠️ seats_left = null بلا سعةٍ معلَنة
    "members_count": 12,
    "schedule_preview": ["الأحد ١٨:٠٠", "الأربعاء ١٨:٠٠"]  // FR-028أ — الاختيارُ بين أسماءٍ مجرّدةٍ ليس اختياراً
  }]
}
```

```jsonc
// GET /cohorts/{cohort}/roster   ← شكلُ ReadSessionRoster + المرتبة
{ "members": [{
    "uuid": "…", "name": "سارة أحمد",                 // ⚠️ first_name + last_name — «users» بلا عمودِ name
    "avatar_url": "…",
    "level": 4,
    "rank": 7,                                         // ⚠️ المفتاحُ يغيبُ كلّيّاً لمن لا مرتبةَ له — لا صفر
    "badges": [{ "key": "streak_7", "name_ar": "أسبوعٌ متّصل", "icon": "flame" }]
}] }
```
⚠️ **ولا حقلَ حضورٍ ولا مدّةِ بقاءٍ ولا درجةٍ ولا ملاحظةِ مدرّسٍ في هذه الحمولةِ إطلاقاً** (FR-052). تلك أسئلةُ `ATTENDANCE_VIEW`، وهذا المسارُ يفتحُ لكلِّ عضو.

---

## ج. المجموعات — كتابةُ الطالب · `throttle:cohort-write`

| المسار | الدلالة |
|---|---|
| `POST /cohorts/{cohort}/join` | الانضمامُ الأوّلُ — حرٌّ (FR-028د) |
| `POST /cohorts/{cohort}/transfer-requests` | طلبُ انتقالٍ (FR-028هـ) — `{ reason? }` |
| `DELETE /transfer-requests/{request}` | سحبُ الطلبِ |

**الرفض** — `422` بجملةٍ عربيّةٍ ورمزٍ:

| الرمز | متى |
|---|---|
| `cohort_full` | السعةُ بلغت — الاقتناصُ الذرّيُّ أثّرَ صفرَ صفوف |
| `already_member` | له عضويّةٌ مفتوحةٌ ⇒ الطريقُ هو طلبُ انتقالٍ لا انضمام |
| `request_pending` | له طلبٌ معلَّقٌ بالفعل |
| `same_cohort` | الوجهةُ هي مجموعتُه — يُرفَضُ عندَ التقديمِ لا عندَ الموافقة |
| `cohort_closed` | مغلقةٌ للانضمامِ أو مؤرشَفة |
| `not_enrolled` | ⇐ `403` — لا تسجيلَ نشطاً في المادّة |

---

## د. المجموعات — المدرّس · `throttle:cohort-write`

| المسار | ملاحظة |
|---|---|
| `GET · POST /manage/courses/{course}/cohorts` | الإنشاءُ متاحٌ لنوعِ `group` وحدَه (FR-037) |
| `PATCH · POST /manage/cohorts/{cohort}/archive` | ⚠️ **لا `DELETE`** — الحذفُ ممنوعٌ والأرشفةُ هي البديل (FR-035) |
| `GET /manage/cohorts/{cohort}/members` · `POST /members` · `DELETE /members/{user}` | إضافةٌ وإخراجٌ مباشران — بلا طلبٍ ولا موافقة (FR-028ط) |
| `GET /manage/cohorts/{cohort}/history` · `GET /manage/courses/{course}/students/{student}/cohort-history` | السجلُّ (FR-034) |
| `GET /manage/courses/{course}/transfer-requests` | الطابور |
| `POST /manage/transfer-requests/{request}/approve` · `/reject` | ⚠️ `reject` يشترطُ `reason` (FR-028ح) |
| `GET /manage/courses/{course}/unassigned-sessions` · `POST /manage/courses/{course}/assign-sessions` | ⚠️ **FR-025هـ** — العددُ والقائمةُ والإسنادُ دفعةً واحدة |

⚠️ **`approve` قد يفشلُ بـ`cohort_full`** ولو كان الطلبُ سليماً يومَ تقديمِه: السعةُ تُقاسُ هنا. والفشلُ لا يمسُّ عضويّةَ الطالبِ القائمةَ بشيء.

⚠️ **الإسنادُ الجماعيُّ إجراءٌ واحدٌ لا حلقةٌ عندَ العميل** — أربعون طلباً هي أربعون فرصةً لأن يفشلَ واحدٌ في المنتصفِ فيبقى نصفُ الجدولِ محجوباً بلا ما يقولُ أيُّ نصف. نفسُ قاعدةِ «إجراءُ المضيفِ الجماعيُّ طلبٌ واحد» في ٠١٨.

---

## هـ. الشات — الإشراف · `throttle:chat-write`

| المسار | ملاحظة |
|---|---|
| `POST /manage/conversations/{conversation}/lock` · `/unlock` | ⚠️ `SetConversationLock` **قائمٌ** — يُوسَّعُ للنوعِ الجديدِ لا يُعادُ كتابتُه |
| `POST /manage/conversations/{conversation}/write-bans` | `{ user_uuid, reason, minutes? }` — بلا `minutes` = مفتوح |
| `DELETE /manage/conversations/{conversation}/write-bans/{ban}` | الرفعُ اليدويّ |

**الكتابةُ في الخيط**: `POST /conversations/{conversation}/messages` القائمُ — بلا مسارٍ جديد. الرفضُ من `ConversationPolicy::post()` بجملةٍ، والمنعُ الجديدُ فرعٌ فيها.

| الرفض | الجملة |
|---|---|
| مكتوم | «أغلق المدرّس النقاش مؤقّتاً. يمكنك القراءة.» ← نصُّ الغرفةِ القائم |
| ممنوعٌ مؤقّتاً | «مُنِعت من الكتابة في هذا النقاش حتى {وقت}. {السبب}» |
| ممنوعٌ مفتوحاً | «مُنِعت من الكتابة في هذا النقاش. {السبب}» |
| انتقلَ منها | «انتقلت إلى مجموعة أخرى. يمكنك قراءة هذا النقاش.» |

⚠️ **الحظرُ على مستوى المساحةِ وانتهاءُ نشاطِ المدرّسِ يعملان بلا سطرٍ واحد** — كلاهما مسؤولٌ عن كلِّ الأنواعِ في `post()` قبلَ أيِّ فرعِ نوع. ⇒ FR-047 مجّاناً.

---

## و. البثُّ اللحظيّ

الحدثُ القائمُ نفسُه على قناةٍ خاصّةٍ بالخيط. ⚠️ **الحمولةُ معرّفانِ لا جسمُ الرسالة**: القناةُ تُصرَّحُ مرّةً عندَ الاشتراكِ ولا يملكُ البروتوكولُ سحبَ التصريح، فعضوٌ أُغلِقَتْ عضويّتُه يظلُّ يستقبلُ الإطاراتِ حتى يُغلِقَ اللسان — وهو غيرُ ضارٍّ ما دامَ الجلبُ الذي تُثيرُه يمرُّ بالمسارِ المُصادَقِ عليه ويُرَدُّ هناك. وضعُ الجسمِ في الإطارِ يجعلُ السحبَ ساريَ المفعولِ عندَ إغلاقِ التبويبةِ لا قبل.

⚠️ **الحفظُ هو المرجع** (FR-048 · SC-014): الرسالةُ تُكتَبُ ثمّ تُبَثّ. مع تعطيلِ المقبسِ بالكاملِ تُقرَأُ عندَ أوّلِ تحديث.

---

## ز. مرشِّحاتٌ تُضافُ لمساراتٍ قائمة

```
GET /exams?course={uuid}          ← ExamController@index — بلا مرشِّحٍ اليوم
GET /assignments?course={uuid}    ← AssignmentController@index — بلا مرشِّحٍ اليوم
GET /certificates?course={uuid}   ← CertificateController@index
GET /class-sessions?course={uuid} ← قائمٌ · يكتسبُ حجبَ غيرِ المُسنَدِ ومرشِّحَ المجموعة
```
⚠️ **المرشِّحُ يُطابَقُ عبرَ العلاقةِ بالـuuid**، فمعرّفٌ مجهولٌ يُطابِقُ **لا شيء** — نمطُ `ClassSessionController@index` القائم. ومرشِّحٌ يُقارِنُ معرّفاً داخليّاً هو كشفُ عقدٍ لم يُقصَد.
