# Contract — نقاطُ النهايةِ والقنوات (010)

كلُّ مسارٍ تحتَ `/api/v1`. كلُّ رابطٍ يكشف `uuid` وحدَه. كلُّ محدّدٍ **مُسمّى** — و`throttle:5,1`
داخلَ المسارِ ممنوعٌ (research §R9).

## فريقُ المدرّس (US1)

| Method | Path | من | المحدِّد |
|---|---|---|---|
| `GET` | `/manage/assistants` | مالكُ المساحة | — |
| `POST` | `/manage/assistants` | مالكُ المساحة | `throttle:authoring` |
| `PUT` | `/manage/assistants/{assignment}/abilities` | مالكُ المساحة | `throttle:authoring` |
| `DELETE` | `/manage/assistants/{assignment}` | مالكُ المساحة | `throttle:authoring` |
| `GET` | `/assistants/me` | المساعدُ نفسُه | — |

⚠️ **مالكُ المساحةِ لا «عضوٌ بصلاحيّةٍ واسعة»**: `workspaces.owner_user_id` هو العمودُ الوحيدُ
الذي يقول لمن المساحة. مساعدٌ يعيّن مساعداً يرفع سقفَه بنفسِه — وهو نفسُ الحارسِ الذي كتبه
`TeacherOffboardingPolicy` في ٠١٣.

**الحمولةُ لا تحمل أيَّ حقلٍ ماليّ**، ويحرسها `AssistantPayloadAllowlist` على شاكلةِ
`TeacherFieldAllowlist` و`StudentBalanceAllowlist`.

## المحادثاتُ والرسائل (US2 · US3)

| Method | Path | ملاحظة |
|---|---|---|
| `GET` | `/conversations` | قائمةُ المستخدمِ مرتّبةً بـ`last_message_id` |
| `POST` | `/conversations` | يفتح خاصّةً مع مدرّسٍ — ويُرجع القائمةَ إن وُجدت |
| `GET` | `/conversations/{conversation}/messages` | ⚠️ `?before={id}` — مفتاحٌ لا إزاحة |
| `POST` | `/conversations/{conversation}/messages` | `throttle:chat-write` |
| `DELETE` | `/messages/{message}` | إخفاءٌ لا إزالة |
| `POST` | `/messages/{message}/helpful` | مدرّسٌ أو مفوَّض · يُطلق حدثَ التلعيب |
| `POST` | `/messages/{message}/report` | `throttle:chat-write` (`FR-024`) |
| `GET` | `/sessions/{classSession}/chat` | يحلّ محادثةَ الحصّةِ العامّةَ أو يُنشئها |
| `POST` | `/moderation/bans` · `DELETE /moderation/bans/{ban}` | `throttle:moderation-write` |

⚠️ **`GET /conversations/{conversation}/messages` بعددِ استعلاماتٍ ثابت** (`SC-009` ·
`NFR-010`): اسمُ المُرسِلِ ورتبتُه ومستواه محمَّلةٌ مسبقاً، والرتبةُ من ٠٠٩ تُوسَم على
المجموعةِ دفعةً — الشكلُ الجَمْعيُّ الذي كتبه `WithholdingReader::stamp()`. قراءةٌ لكلِّ صفٍّ
هي N+1 بالبناء على شاشةٍ طولُها بلا سقف.

## القناة (`NFR-007` · `NFR-008`)

```
private-conversation.{uuid}
```

- التفويضُ في `routes/channels.php` يستدعي **حارسَ الفعلِ نفسَه**، لا شرطاً ثانياً بجانبه.
- الحمولةُ `{message_uuid, conversation_uuid}` **ولا شيءَ غيرها**. العميلُ يجلب الرسالةَ
  بالمسارِ المُصادَقِ عليه.
- ⚠️ **`FR-014`**: انتهاءُ التسجيلِ يمنع الإرسالَ ويُبقي الأرشيفَ مقروءاً — فالتفويضُ للقراءةِ
  والتفويضُ للكتابةِ سؤالان، ودمجُهما يُغلق أرشيفاً وُعد به.

## التقييمُ المتبادل (US4)

| Method | Path | ملاحظة |
|---|---|---|
| `POST` | `/manage/students/{student}/reviews` | تقييمٌ دوريّ · `throttle:authoring` |
| `POST` | `/manage/students/{student}/reviews/{review}/publish` | يُطلق إشعارَ الطالبِ ووليِّ أمره |
| `GET` | `/students/me/reviews` | الطالبُ ووليُّ أمره |
| `POST` | `/teachers/{teacher}/rating` | ⚠️ `409` قبلَ بلوغِ الحدِّ الأدنى، **برسالةٍ تقول كم يتبقّى** |
| `GET` | `/teachers/{teacher}/rating/eligibility` | ما ينقص، قبلَ عرضِ النموذج |

⚠️ **الأهليّةُ نقطةٌ مستقلّةٌ تشتقُّ من مسندِ التفويضِ نفسِه** — لا شرطٌ ثانٍ في الواجهة.
درسُ `ListLeaderboardScopes` في ٠٠٩: قائمةٌ مبنيّةٌ بجانبِ الحارسِ تعرض ما يرفضه الخادمُ
وتُخفي ما يسمح به.

## الإعلانات (US6)

| Method | Path | المحدِّد |
|---|---|---|
| `GET` · `POST` | `/manage/announcements` | `throttle:announcement-publish` |
| `PATCH` · `DELETE` | `/manage/announcements/{announcement}` | `throttle:announcement-publish` |
| `GET` | `/manage/announcements/{announcement}/stats` | — |

**لا مسارَ ردٍّ جماعيّ** (`FR-045`). الردُّ يفتح محادثةً خاصّة.

## الأوزانُ والكشف (US5)

| Method | Path | ملاحظة |
|---|---|---|
| `GET` · `PUT` | `/manage/grading-schemes` | المجموعُ ١٠٠ يُفرَض في الفعل |
| `POST` | `/manage/report-cards` | يُنشئ ويُطبِر التصيير |
| `GET` | `/report-cards` · `/report-cards/{card}` | الطالبُ ووليُّ أمرِه |
| `GET` | `/report-cards/{card}/download` | ⚠️ `302` إلى رابطٍ **موقَّت**، لا مسارٍ في الحمولة |

⚠️ **التنزيلُ يعيد `302` إلى توقيعٍ قصيرِ العمر**، لا `file_path` في الحمولة — نفسُ قاعدةِ
`DataRequestResource` و`PlaybackGrantResource`: مسارٌ في JSON رابطٌ يُنسَخ ويُلصَق في تذكرةٍ
ويبقى.

## الأحداثُ المُصدَّرة

| الحدث | الحمولة | لمن |
|---|---|---|
| `HelpfulAnswerMarked` | `student_user_id` · `workspace_id` · `message_id` | Gamification |
| `TeacherRated` | `teacher_profile_id` · `rating` | Marketplace |
| `AssistantAbilitiesChanged` | `assistant_user_id` · `workspace_id` | Identity (‏إنهاءُ الجلسات) |

⚠️ **ولا يستدعي `Community` فعلاً في وحدةٍ أخرى**، ولا العكس. المستمعُ يُسجَّل بـ`Event::listen()`
في مزوّدِ الوحدةِ **المشترِكة** — لا في مزوّدِ `Community`.
