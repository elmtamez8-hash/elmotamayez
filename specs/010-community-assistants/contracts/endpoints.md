# Contract — نقاطُ النهايةِ والقنوات (010)

**الإصدارُ الثاني.** كلُّ مسارٍ تحتَ `/api/v1`. **كلُّ رابطٍ ومؤشِّرٍ يكشف `uuid` وحدَه** —
الإصدارُ الأوّلُ كتب `?before={id}` وهو ما يمنعه الدستورُ VI. كلُّ محدّدٍ **مُسمّىً ومُسجَّلٌ
في `registerRateLimiters()`** — واسمٌ غيرُ مُسجَّلٍ يُقرأ صفراً في الدقيقةِ فيردّ `429` دائماً.

## فريقُ المدرّس (US1)

| Method | Path | من | المحدِّد |
|---|---|---|---|
| `GET` | `/manage/assistants` | مالكُ المساحة | — |
| `PUT` | `/manage/assistants/{assignment}/scope` | مالكُ المساحة | `throttle:authoring` |
| `DELETE` | `/manage/assistants/{assignment}` | مالكُ المساحة | `throttle:authoring` |
| `GET` | `/assistants/me` | المساعدُ نفسُه | — |

⚠️ **لا `POST /manage/assistants`.** `workspace_members` لا يكتبه إلا `AcceptInvitation` و
`CreateWorkspace`، والدعوةُ والقبولُ والأعضاءُ مشحونةٌ كلُّها في `Tenancy`
(`POST /workspaces/{workspace}/invitations` · `.../invitations/{token}/accept` ·
`GET|DELETE /workspaces/{workspace}/members[/{member}]`) ومعها شاشاتُها. التعيينُ يُنشَأ عند
**قبولِ** دعوةٍ بدورِ `assistant-teacher` — مستمعٌ على القبول، لا نقطةُ إنشاءٍ ثانية.

⚠️ **ولا نقطةَ «منحُ بنود».** البنودُ صلاحيّاتُ spatie تُمنَح من شاشةِ الأدوارِ القائمة —
`RolePermissionMatrix` يقول ذلك بنصِّه. ما تضيفه هذه المرحلةُ هو **النطاق** وحدَه.

⚠️ **ومالكُ المساحةِ لا «عضوٌ بصلاحيّةٍ واسعة»**: `workspaces.owner_user_id` هو العمودُ الذي
يقول لمن المساحة. مساعدٌ يوسّع نطاقَ مساعدٍ يرفع سقفَ نفسِه.

## الحائطُ الماليّ — بلا نقطةٍ، وهو المقصود

`FR-003` لا يضيف مساراً؛ يضيف **رفضاً على مساراتٍ قائمة**. الحارسُ `Gate::before` مفتاحُه صفُّ
`assistant_assignments` الحيُّ، ومجموعتُه **مُشتقّةٌ** من بادئاتِ `settlement.` · `billing.` ·
`payments.` · `orders.` ناقصَ `billing.balance.view`.

⚠️ **ويغطّي لوحةَ `/admin` كما يغطّي الـAPI.** `EnsureFilamentAccess` يُدخل `assistant-teacher`
بالاسم، **وقائمةُ Filament لا تستدعي سياسةَ الصفِّ أبداً** — العطلُ الذي وُجد مشحوناً في
`OrderResource` وأُصلح في `981ca23`. فكلُّ موردٍ يعرض مالاً يحتاج `canViewAny()` وقَطعاً على
`getEloquentQuery()`.

## المحادثاتُ والرسائل (US2 · US3)

| Method | Path | ملاحظة |
|---|---|---|
| `GET` | `/conversations` | ⚠️ يُصفّي بـ`conversation_participants` **صراحةً** |
| `POST` | `/conversations` | `throttle:chat-write` · يلتقط خرقَ الفريدِ ويُرجع الفائز |
| `GET` | `/conversations/{conversation}/messages` | ⚠️ `?before={uuid}` — يُحلُّ داخلَ الفعلِ بعد التحقّقِ أنه لهذه المحادثة |
| `POST` | `/conversations/{conversation}/messages` | `throttle:chat-write` |
| `DELETE` | `/messages/{message}` | إخفاءٌ (`hidden_at`) لا إزالة |
| `POST` | `/messages/{message}/helpful` | مدرّسٌ أو مفوَّض · تحديثٌ شرطيّ `WHERE is_helpful = 0` |
| `POST` | `/messages/{message}/report` | ⚠️ `throttle:chat-report` — **وعاءٌ مستقلّ**: من خُنق عن الكتابةِ يجب أن يستطيع الإبلاغَ عن إساءة |
| `GET` | `/sessions/{classSession}/chat` | يحلّ محادثةَ الحصّةِ أو يُنشئها |
| `POST` | `/moderation/actions` | `throttle:moderation-write` · **الحظرُ والرفعُ صفّان، لا حذف** |

⚠️ **كلُّ معرّفٍ في هذه المسارات يُحلَّ داخلَ الفعلِ بعد فحصِ العضويّة، لا بارتباطٍ ضمنيّ.**
الطالبُ عضوٌ في **لا مساحةَ عمل**، فـ`WorkspaceContext::id()` معدومٌ و`WorkspaceScope` لا يضيف
شرطاً — فارتباطٌ ضمنيٌّ على `{conversation}` أو `{message}` يحلُّ صفَّ **أيِّ** مساحةٍ قبل أن
تعمل أيُّ سياسة: طفلٌ يلصق معرّفَ محادثةٍ ويقرأ رسائلَ طفلٍ آخرَ مع مدرّسِه. نمطُ `RedeemReward`
من ٠٠٩ حرفياً.

⚠️ **وقائمةُ المحادثاتِ تُصفّى بجدولِ المشاركين صراحةً** للسببِ نفسِه — بلا ذلك تُرجع محادثاتِ
كلِّ مساحةٍ على المنصّة.

⚠️ **وصفحةُ الرسائلِ بعددِ استعلاماتٍ ثابت**: الاسمُ والرتبةُ والمستوى تُوسَم على المجموعةِ
بنداءٍ واحدٍ لـ`ReadRanksFor`. **ولا `paginate()`** — عدُّه الكاملُ استعلامٌ واحدٌ عند كلِّ
حجمِ تثبيتة، فلا يراه اختبارُ الميزانيّةِ بحجمَين ويُسقط نصفَ `SC-009` الزمنيَّ وحدَه.

## القناة (`NFR-007` · `NFR-008`)

```
private-conversation.{uuid}      # الرسائل
private-user.{uuid}              # «فُتحت معك محادثة» · عدّادُ غيرِ المقروء
```

- التفويضُ في `routes/channels.php` يستدعي **حارسَ الفعلِ نفسَه**، لا شرطاً ثانياً بجانبه.
- الحمولةُ `{message_uuid, conversation_uuid}` **ولا شيءَ غيرها**.
- ⚠️ **والقناةُ تُفوَّض مرّةً عند الاشتراكِ ولا يملك البروتوكولُ إلغاء.** فمساعدٌ مسحوبةٌ
  صلاحيّتُه وما زال متّصلاً يستمرّ في تلقّي الأحداث؛ **حملُ المعرّفِ وحدَه هو ما يجعل ذلك
  محتمَلاً**، إذ يمرُّ الجلبُ اللاحقُ بالمسارِ المُصادَقِ عليه فيُرفَض. هذا سببٌ ثانٍ للقاعدةِ
  ويُكتب بجانبها.
- `/broadcasting/auth` مسارٌ مُصادَقٌ عليه بمحدِّدٍ مُسمّى — وهو الموضعُ الوحيدُ الذي تُعدَّد
  فيه أسماءُ القنوات.
- ⚠️ **و`FR-014`**: انتهاءُ التسجيلِ يمنع الإرسالَ ويُبقي الأرشيفَ مقروءاً — تفويضُ القراءةِ
  وتفويضُ الكتابةِ سؤالان، ودمجُهما يُغلق أرشيفاً وُعد به.

## التقييمُ المتبادل (US4)

| Method | Path | ملاحظة |
|---|---|---|
| `POST` | `/manage/students/{student}/reviews` | ⚠️ يسأل `EnrollmentDirectory` أوّلاً — معرّفٌ عارٍ مسبارُ هويّة (`NFR-001أ`) |
| `POST` | `/manage/students/{student}/reviews/{review}/publish` | تحديثٌ شرطيٌّ فيصل الإشعارُ مرّةً |
| `GET` | `/students/me/reviews` | الطالبُ ووليُّ أمره |
| `POST` | `/teachers/{teacher}/reviews` | ⚠️ **المسارُ المشحونُ في `Marketplace`، يُوسَّع** — لا نقطةَ ثانية |
| `GET` | `/teachers/{teacher}/reviews/eligibility` | ما ينقص، قبلَ عرضِ النموذج |

⚠️ **الأهليّةُ تُشتقُّ من مسندِ التفويضِ نفسِه** — درسُ `ListLeaderboardScopes`: قائمةٌ مبنيّةٌ
بجانبِ الحارسِ تعرض ما يرفضه الخادمُ وتُخفي ما يسمح به. والبوّابةُ تستبدل
`hasCompletedSessionWith()` القائمةَ في مكانها (‏research §R9).

## الإعلانات (US6)

| Method | Path | المحدِّد |
|---|---|---|
| `GET` · `POST` | `/manage/announcements` | `throttle:announcement-publish` |
| `POST` | `/manage/announcements/{announcement}/publish` | ⚠️ مطالبةٌ شرطيّة — والحدثُ للمطالِبِ وحدَه |
| `PATCH` · `DELETE` | `/manage/announcements/{announcement}` | `throttle:announcement-publish` |
| `GET` | `/manage/announcements/{announcement}/stats` | — |

**لا مسارَ ردٍّ جماعيّ** (`FR-045`). **والنطاقُ `all` · `course` · `session`** — «المجموعات»
خارجَ النطاقِ مُعلَناً (ق-٥).

## الأوزانُ والكشف (US5)

| Method | Path | ملاحظة |
|---|---|---|
| `GET` · `PUT` | `/manage/grading-schemes` | المجموعُ ١٠٠ يُفرَض في الفعل |
| `GET` | `/manage/report-cards` | ⚠️ **مقطعُ المدرّسِ هو وحدَه** |
| `GET` | `/report-cards` · `/report-cards/{card}` | الطالبُ ووليُّ أمرِه — والمعرّفُ يُحلُّ داخلَ الفعل |
| `GET` | `/report-cards/{card}/download` | `302` إلى توقيعٍ قصيرِ العمرِ مربوطٍ بالطالب · `throttle:report-card-render` |

⚠️ **ولا `POST /manage/report-cards`.** البناءُ وظيفةٌ مجدولةٌ منصّيّةٌ لفترةٍ واحدة (ق-٤):
كشفٌ يطلقه مدرّسٌ إمّا يقرأ درجاتِ زميلِه أو يُنتج مقطعاً واحداً يُعرَض كسجلِّ الطالب،
ويتصادم مدرّسان على المفتاحِ الفريدِ بفترتَين مختلفتَي النهاية.

## الأحداثُ المُصدَّرة

| الحدث | الحمولة | لمن |
|---|---|---|
| `HelpfulAnswerMarked` | `student_user_id` · `workspace_id` · **`source_type` + `source_id`** | Gamification |
| `PeriodicReviewPublished` | `review_id` | Notifications |
| `AnnouncementPublished` | `announcement_id` | Community (‏وظيفةُ التفريع) |

⚠️ **`source_type`/`source_id` في حمولةِ المنح** ليسا زينة: مفتاحُ التعامدِ في `AwardPoints`
مبنيٌّ عليهما، وبدونهما تُمنَح النقاطُ مرّتَين على ضغطتَين.

⚠️ **ولا `TeacherRated`**: `ReviewSubmitted` مشحونٌ ومربوطٌ بـ`QueueTrustScoreRecalculation`.

⚠️ **والمستمعُ يُسجَّل في مزوّدِ الوحدةِ المشترِكة** لا في `Community`.
