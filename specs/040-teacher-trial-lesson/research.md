# Research: الحصة التجريبية للمدرّس

كل قرار هنا مبنيّ على قراءة الكود في 2026-10-09. المسارات نسبيّة لجذر المستودع.

## R1 — أين تُحفظ العلامة

- **Decision**: عمود nullable اسمه `teacher_profiles.trial_lesson_id`، وهو FK إلى `lessons.id` مع `nullOnDelete`.
- **Rationale**:
  - FR-002 («حصة واحدة لكل مدرّس») يتحقّق بالبناء: عمود واحد لا يحمل قيمتين.
  - التعليم الجديد مجرّد `UPDATE` يستبدل القيمة، فلا سباق بين طلبين، ولا يحتاج قفلاً أو عدّاً.
  - حذف الدرس يُسقط العلامة تلقائياً (Edge case).
  - والملف العام هو صاحب القرار، بحسب افتراض الـ spec.
- **Alternatives considered**:
  - عمود `lessons.is_trial` مع فهرس unique جزئي. هذا يحتاج فهرساً على (مدرّس، علامة)، والمدرّس غير مخزَّن على الدرس؛ مالك الكورس مشتقّ من `Course::teacherUser()`. فـ«واحدة لكل مدرّس» لا يُعبَّر عنها بفهرس، وتصير read-then-write: نفس عائلة عطل `claimCapacity` في live-sessions.md.
  - استعمال `is_preview`. رفضه المالك صراحةً، لأن مدرّسين علّموا دروساً مفتوحة قاصدين المسجَّلين وحدهم.

## R2 — قاعدة الصلاحية الواحدة (`TrialLessonRule`)

- **Decision**: كلاس واحد له وجهان:
  - `eligibilityRefusal(TeacherProfile, Lesson): ?string`: سبب الرفض عند **التعليم**، بالعربية.
  - `currentFor(TeacherProfile): ?Lesson`: هل الحصة المحفوظة صالحة **الآن** للعرض والتشغيل.
- **الشروط الثابتة** (FR-004)، تُفحص عند التعليم وعند كل قراءة:
  - النوع `embed` أو `video` فقط.
  - `class_session_id` فارغ: الدرس ليس تسجيل حصة.
  - `release_session_id` فارغ، ولا صفوف له في `lesson_cohort_scopes`: جمهوره غير مقصور. هما المحوران اللذان يسألهما `LessonAudience`، والكشف نفسه في `PublicCourseDetailResource::narrowedLessonIds()`.
  - `is_high_value` = false.
  - صاحب الكورس هو هذا المدرّس: `Course::teacherUser()->id === teacher_profiles.user_id`. لا «عضو في مكان العمل» ولا «أنشأه».
- **الشروط المتغيّرة** (FR-007)، تُفحص عند القراءة فقط:
  - `visibleToStudents()` على الدرس وفصله وقسمه.
  - الكورس عامّ عبر `ReadPublicCourse`، الذي يشترط منشوراً، وعامّاً، ومدرّساً معتمداً ومعروضاً، ومكان عمل مشاركاً.
  - المدرّس نفسه `publiclyListed()`.
  - للدرس المرفوع: أصل الفيديو الأساسي (`mediaAsset()`، role primary، kind video) جاهز للتشغيل (`isPlayable`).
- **Rationale**: نسختان من القاعدة ستتباعدان، وصفحة المدرّس تعلن حينها حصة يرفضها الباب. هذا رابط ميّت على الزرّ الرئيسي، وهو بالضبط ما حذّر منه `PublicCourseDetailResource` (سطر 202).
- **Alternatives considered**: توسيع `ReadPublicPreviewLesson::readable()` ليقبل `video`. رُفض لأن `readable()` يجيب عن «أيّ درس مفتوح يقرؤه الزائر»، وفتحه للفيديو المرفوع هو الثغرة بعينها (spec، الجدول).

## R3 — `trialLessonOf` بعد #375

- **Decision**: `ShowPublicTeacher::trialLessonOf($teacher)` يقرأ `TrialLessonRule::currentFor($teacher)` فقط، ويُحذف الاختيار التلقائي (FR-016).
- **الحمولة**: `trial_lesson` تصير `{course_slug, lesson_uuid, title, kind: "embed"|"video", duration_seconds?}` أو `null`.
  - `kind` يقول للواجهة أيّ مشغّل تستعمل.
  - `duration_seconds` يُحذف إن كان صفراً، كما يفعل `PublicPreviewLessonResource`.
- **Rationale**: المدرّس الذي لم يعلّم شيئاً لا يجب أن يجد درساً قديماً مضمَّناً صار فجأة «حصته التجريبية».

## R4 — باب الزائر للفيديو المرفوع

- **Decision**: مساران عامّان، كلاهما بمفتاح المدرّس **فقط** (slug أو uuid) ولا يقبلان معرّف درس:
  - `GET /api/v1/marketplace/teachers/{key}/trial/playback`: وصف التشغيل. شكله شكل `PlaybackGrant` في الواجهة، بلا `grant` ولا `watermark`، مع `renew_after_seconds: null` و`resume_at_seconds: 0` (contracts/api.md).
  - `GET /api/v1/marketplace/teachers/{key}/trial/stream`: البثّ نفسه.
- **ما يفعله البثّ**: يحلّ `ReadTrialPlayback` الحصة عبر `TrialLessonRule::currentFor`، ثم أصلها، ثم المزوّد بـ`MediaProviderResolver::for($asset)`.
  - **Bunny**: ينادي `manifest()` بـ`PlaybackContext` يحمل `PlaybackGrant` **غير محفوظ** (`new PlaybackGrant(['expires_at' => now()+TTL])` بلا `save()`). Bunny لا يقرأ منه إلا `expires_at` (`BunnyMediaProvider.php:349`). النتيجة 302 إلى الرابط الموقَّع. والتوقيع نفسه (`token_path`) لا يتغيّر.
  - **المحلي**: `LocalMediaProvider::manifest()` يبني رابطاً بمعرّف التصريح، فلا ينفعنا. البثّ يخدم البايتات بنفسه، بنفس كتلة `PlaybackController::stream()` (`disk()->response` مع Range). نستخرجها إلى دالة صغيرة يشترك فيها المساران، أو ننسخها بتعليق يشير إلى الأصل؛ الأصغر يُحسم في المهام.
- **Rationale**:
  - الباب لا يأخذ معرّف درس، فلا شيء يُخمَّن: مفتاح المدرّس لا يقود إلا لحصته الحالية (FR-009).
  - لا صفّ يُكتب، فلا مساس بأعمدة التصريح NOT NULL ولا بـ`PlaybackGuard` (FR-014).
  - الرابط الموقَّع يبقى قصيراً (FR-010)، والمشغّل يعود عبر مسارنا بانتظام بآلية `reload_after_seconds` الموجودة في `VideoPlayer.tsx:131-146`، فالحصة الطويلة لا تنقطع (SC-002).
- **Alternatives considered**:
  - جعل `playback_grants.user_id` و`auth_session_id` nullable وإصدار تصريح «زائر». رُفض لأنه يُضعف «انتهت الجلسة ⇒ مات التصريح» لكل الطلاب، ويمسّ `MediaPersonalData` والتجديد.
  - تسليم رابط Bunny الموقَّع مباشرةً في الحمولة. رُفض لأن `docs/gotchas/media.md` تقول إن `manifest_url` مسارنا دائماً، لا رابط المزوّد، كي لا يظهر معرّف المكتبة والفيديو في أي حمولة (FR-019 في 004).

## R5 — أين يشاهد الزائر

- **Decision**: نعيد استعمال صفحة الدرس العامة `/courses/{slug}/lessons/{uuid}`، فهي تعرض المضمَّن اليوم. نضيف فرعاً: إن لم يُرجع الباب القديم الدرس، وكان هذا الدرس هو الحصة التجريبية المرفوعة لمدرّس الكورس، تشغّله الصفحة بـ`TrialPlayer`.
- **الطريقة الأبسط**: الصفحة تقرأ حمولة المدرّس، `trial_lesson` و`slug`، من `publicApi.course(slug)`. ثم تقارن `lesson_uuid`، ثم تجلب `trial/playback`.
- **Rationale**:
  - رابط واحد لكل حصة تجريبية، أياً كان نوعها.
  - زرّ صفحة المدرّس يبني الرابط نفسه في الحالتين.
  - لا صفحة جديدة تحتاج رابطاً داخلياً إليها (ذاكرة «every new surface needs an inbound link»).
- **Alternatives considered**: صفحة `/teachers/{slug}/trial`. أبسط في الكود، لكنها رابطان مختلفان للشيء نفسه بحسب النوع، والحصة المضمَّنة تُعرض أصلاً في صفحة الدرس. ويبقى هذا البديل مقبولاً إن ظهر في المهام أن فرع صفحة الدرس معقّد.

## R6 — حدّ الطلبات

- **Decision**: limiter جديد اسمه `trial-playback`، مفتاحه `ip:` + IP الطلب، على المسارين معاً. رقماه في `platform_settings`:
  - `media.trial_requests_per_minute`، افتراضياً 30.
  - `media.trial_link_ttl_seconds`، افتراضياً 600.
- **حساب الافتراض**: مشاهدة واحدة تحتاج طلب وصف، ثم طلب بثّ عند البدء، ثم طلباً كل ثلثي الـ TTL تقريباً (`reload_after_seconds`). فـ30 في الدقيقة تتّسع لعدّة مشاهدين خلف IP واحد (مدرسة أو شبكة جوال)، وتوقف الزحف.
- **Rationale**: `throttle:public` يخدم صفحات السوق بـ60 لكل IP، وخلطه بالبثّ يجعل مشاهدة الفيديو تأكل حصّة الصفحات (FR-013). و`throttle:playback` مفتاحه المستخدم، وللزائر يصير دلواً واحداً للجميع.
- **ملاحظة**: الأرقام التشغيلية في `platform_settings` لا في `config/` (deploy-ops.md).

## R7 — الفيديو التعريفي بديلاً

- **Decision**: لا تغيير في الخادم؛ `intro_video_url` في الحمولة أصلاً. `TrialCta` يأخذ `introVideo: boolean` و`introHref`:
  - إن لم توجد حصة ووُجد فيديو تعريفي، فالزرّ «شاهد فيديو المدرّس» ويذهب إلى قسم الفيديو التعريفي على الصفحة نفسها (anchor `#intro-video`، ويُضاف `id` لحاويته). ولا يفتح نافذة جديدة.
- **Rationale**: الفيديو التعريفي يُعرض مضمَّناً على صفحة المدرّس اليوم، فالزرّ يأخذ الزائر إليه ويبدأ التشغيل بضغطة واحدة دون مشغّل ثانٍ.

## R8 — من يرى مفتاح «حصتي التجريبية» في المحرّر

- **Decision**: `/auth/me` أو حمولة الدرس في المحرّر تحمل `can_set_trial: boolean` و`is_trial: boolean` من الخادم، كما تحمل الشاشات `can_change_pricing` اليوم (courses.md). الواجهة تقرأ الـ boolean، ولا تستنتج من الدور.
- **Rationale**: «مساعد يحرّر ولا يقرّر» قاعدة قائمة في الكورسات (`changeVisibility`/`changePricing`)، والشاشة تقرأ قرار الخادم.
