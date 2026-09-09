# Tasks — `031-guardian-credit-topup`

**Input**: `spec.md` · `plan.md` · `research.md` (R1…R17) · `data-model.md` · `contracts/http.md`

**Depends on**: `030-guardian-link-accept` (`eea791f`) — بدونَه لا علاقةَ نشطةً تُسمّي حساباً.

**⛔ خمسُ مهامٍّ تُغلِقُ عيوباً قائمةً، لا ميزةً جديدة**: `T012` (السقفُ يُفرَّغُ بالدفع) ·
`T013` (نطاقٌ في الاستعلامِ الفرعيّ) · `T014` (كورسٌ مسوّدةٌ يُشترى) · `T031`
(`OrderPolicy::pay` بلا نائب) · `T033` (فهرسٌ مفقود).

---

## Phase 1 · Setup

- [X] T001 قِسْ قبلَ أيِّ تغيير: عدَّ الطلباتِ `under_review` التي تحملُ `credit_purchases`، وعدَّ الكورساتِ غيرِ المنشورةِ التي عليها `credit_balances`. الرقمانِ هما مقياسُ `SC-008` و`SC-010` بعدَ الإصلاح.

## Phase 2 · Foundational — الحارسُ الثلاثيّ (يحجبُ كلَّ القصص)

- [X] T002 `Payments/Support/CourseParticipation.php`: `mayBuyFor(?User $grantedBy, User $student, Course $course): void` — **الموضعُ الوحيدُ الذي تُشتَقُّ فيه الصفة**. ⚠️ «لنفسِه» كلّما كانَ `$grantedBy === null` **قبلَ** أيِّ `can()`، وإلّا تخطّى موظّفٌ يشتري لنفسِه شرطَ الطرفيّة (`R12`).
- [X] T003 نفسُه: الصفاتُ الثلاث — لنفسِه ⇒ `isPartyTo($student)` · وصيّ ⇒ `isPartyTo($student)` **و**`isSeller($grantedBy)` · موظّف ⇒ `isSeller($student)` وحدَها **كما هي اليوم** (`FR-003`).
- [X] T004 `Actions/PurchaseCredits.php` و`Actions/ListCreditPackages.php`: يُنادَى `mayBuyFor()` بدلَ الشرطِ المنسوخِ في كلٍّ منهما. **صفرُ نسخٍ باقية.**
- [X] T005 `Support/PurchaseBeneficiary.php`: الرسالتانِ تصيرانِ «اختر الطالب الذي تدفع له.» و«لا يمكنك الدفع لهذا الطالب.»؛ وتعليقٌ يقولُ **نفياً** إنّه بنيويّاً «لنفسِه أو وصيّ» وإنّ صفةَ الموظّفِ مدخلُها اللوحةُ وحدَها (`R12`).
- [X] T006 `tests/Feature/Payments/GuardianSubscribesForChildTest.php`: ثلاثُ توكيداتٍ تُثبِّتُ النصَّينِ القديمَين — تتحرّكُ معهما.

## Phase 3 · US2 — الرفضُ (P1، وقبلَ US1 عمداً)

- [X] T007 `Http/Requests/PurchaseCreditsRequest.php`: `student_uuid` ⇒ `['sometimes','nullable','uuid']`. **بلا `exists:`** — القاعدةُ مِسبارُ هويّة. ⚠️ **`student_uuid` لا `student`**: هو اسمُ ٠٢٩ على الوحدةِ نفسِها.
- [X] T008 `Http/Controllers/CreditPurchaseController.php`: `store()` و`index()` يحسمانِ المستفيدَ بـ`PurchaseBeneficiary` ويمرِّرانِ `(student, grantedBy)`.
- [X] T009 `tests/Feature/Payments/GuardianCreditTopUpTest.php` (جديد): `SC-002` على **البابَين** — تسعيرٌ وشراءٌ على كورسٍ ليسَ الابنُ طرفاً فيه ⇒ صفرُ أسعارٍ وصفرُ طلبات.
- [X] T010 نفسُه: `FR-006` — ثلاثُ حالاتِ هويّةٍ (ليسَ ابنَك · بلا صلاحيّةِ دفع · معرِّفٌ لا وجودَ له) **بالجملةِ نفسِها حرفاً بحرف**.
- [X] T011 نفسُه: `SC-004` — موظّفٌ يمنحُ لطالبٍ بلا أيِّ تسجيلٍ ⇒ يمرُّ. **وحالةٌ ثانيةٌ: موظّفٌ على جهةِ التدريسِ في مساحةِ الكورسِ ⇒ يمرُّ أيضاً** (`FR-003`)، وهي ما لم تكنْ تُقاسُ: `makePlatformStaff` لا يكتبُ محورَ عضويّةٍ إطلاقاً.

## Phase 4 · ⛔ العيوبُ القائمة (تُشحَنُ معاً أو لا تُشحَن)

- [X] **T012** ⛔ `Order`: نطاقُ استعلامٍ واحدٌ (`scopeAwaitingDecision`) يحملُ `['pending','under_review']`، **ويقرؤُه `isPending()` أيضاً**؛ والخمسةُ الحرفيّةُ القائمةُ (`ApproveOrder` · `RejectOrder` · `PurchaseSubscription` · `GrantCreditSubscription` · `Order`) تُطوى فيه. ⚠️ **`isPending()` دالّةُ نسخةٍ ولا تصلحُ داخلَ `whereHas`** — نسخةٌ سادسةٌ حرفيّةٌ هي العطبُ لا الإصلاح.
- [X] **T013** ⛔ `PurchaseCredits::pendingCreditsOn()`: النطاقُ الجديدُ **و**`withoutWorkspaceScope()` **داخلَ** `whereHas('order')` — الالتفافُ الخارجيُّ لا يسري على استعلامِ نموذجٍ آخر.
- [X] **T014** ⛔ `Support/StopSellingGuard::refusalToSell()`: كورسٌ غيرُ منشورٍ لا يبيع. **الموضعُ الذي يقرؤُه البابانِ سلفاً**، فيسري على التسعيرِ والشراءِ والمنتقي بهجاءٍ واحد (`R15` · `R17`).
- [X] T015 `PurchaseCredits`: رسالةُ السقفِ تُفرَّعُ على `$grantedBy` فتُسمّي الابنَ حينَ يقرؤُها غيرُه. **مؤجَّلٌ مع الميزة** — لا نائبَ على البابِ العامِّ بعد.
- [X] T016 `CreditPurchaseController::store()`: `Order` يُعادُ من داخلِ المعاملةِ (أو `setRelation`) — **لا قراءةٌ ثانيةٌ بعدَها**. ⚠️ الأرصدةُ **لا تُسَكُّ** هنا؛ الضررُ طلبٌ معلَّقٌ لا يعرفُ العميلُ معرِّفَه، وكوبونٌ أُحرِق، **وسقفٌ يُستهلَكُ إلى الأبدِ بعدَ `T012`**.
- [X] T017 `courseFor()`: جوابٌ واحدٌ لِـ«لا كورسَ» و«لستَ طرفاً» — عرّافُ وجود. ⚠️ والثمنُ مكتوب: معرِّفٌ مكتوبٌ خطأً يُقرَأُ إذناً.
- [X] T018 `tests/Feature/Payments/CreditPurchaseFlowTest.php` (بجوارِ الحالةِ الشقيقة، لا ملفٌّ ثانٍ): `SC-008` — طلبٌ ⇒ رفعُ إيصالٍ ⇒ طلبٌ ثانٍ ⇒ **يُرفَض**. اليومَ ينجح.
- [X] T019 نفسُه: `SC-009` — **مساحتا عملٍ** والمشتري يُحسَمُ سياقُه إلى الثانية. ⚠️ **و`forget()` لا يصلحُ لذلك**: هو يثبِّتُ null (اعملْ عالميّاً) ولا يُعيدُ الحسم، فالحالةُ المكتوبةُ به تمرُّ خضراءَ على بناءٍ بلا التفافٍ إطلاقاً. `setCurrentWorkspace()` هو الهجاء.
- [X] T020 `tests/Feature/Payments/CreditPurchaseGuardsTest.php` (جديد): `SC-010` على البابَين. ⚠️ **والمنتقي يأتي مع الميزة**. وقد كشفَ هذا الشرطُ أنّ `courseWithRate()` — مُعِينُ كلِّ اختباراتِ الأرصدة — كانَ يُنتِجُ كورساً `draft`: **ثمانِ حالاتٍ احمرَّت، أي أنّ الطقمَ كانَ يُرمِّزُ الثغرة**.

## Phase 5 · US1 — الشحنُ من طرفٍ إلى طرف (P1)

- [X] T021 `tests/Feature/Payments/GuardianCreditTopUpTest.php`: `SC-001`+`SC-005` — علاقةٌ تُبنى بـ`LinkGuardian` ثمّ **تُقبَلُ بمسارِ ٠٣٠**، لا `status => 'active'` في تركيبة؛ ثمّ شراءٌ، فاعتمادٌ، فرصيدٌ **باسمِ الابن** وصفرُ حسابٍ للوصيّ.
- [X] T022 نفسُه: `FR-008` — `orders.user_id` الابنُ و`granted_by` الوصيّ.
- [X] T023 نفسُه: `FR-010` · `SC-006` — الابنُ لا يصلُ إلى إيصالٍ رفعَه الوصيّ.

## Phase 6 · US3 — المنتقي (P2)

- [X] T024 `CourseParticipation::coursesOpenTo(User $student, ?User $payer = null)` — **جبرُ مجموعاتٍ بأربعةِ استعلاماتٍ ثابتة**، لا ترشيحُ كلِّ كورسٍ عبرَ `isPartyTo` (عطبُ N+1 منقولاً إلى `Support/`). والدافعُ في التوقيعِ لأنّ رفضَ البائعِ يُسألُ عنه (`R11`).
- [X] T025 نفسُه: `withoutWorkspaceScope()` على قراءةِ `Course` — `Course` مُنطَّق، ووصيٌّ يملكُ مساحةً يقرأُ **كورساتِ مساحتِه هو** بلا خطأ. والحارسُ البديلُ `whereIn('workspace_id', …)`.
- [X] T026 `Actions/ListPurchasableCourses.php` (جديد) + مورِدٌ **ضيّق**: `uuid` · `title` · اسمُ المدرّس · صورة. ⛔ **لا `CourseResource`** — يحملُ `status` و`visibility` و`price_minor` و`promo_video_status`.
- [X] T027 `PurchasableCourseAllowlistTest` (جديد): يُحمِّرُ البناءَ على حقلٍ يُضاف — هجاءُ `StudentBalanceAllowlist`.
- [X] T028 `Actions/ListPurchaseBeneficiaries.php` (جديد) + `GET /billing/beneficiaries`: `childrenOf($caller, Payments)` حرفيّاً. حمولةٌ `uuid` و`name` وحدَهما.
- [X] T029 `routes/api.php`: البابانِ الجديدانِ بـ`throttle:billing`. وتُرقَّمُ صفحاتُ الكورسات — الذراعُ الثالثةُ غيرُ محدودةٍ بتسجيل.
- [X] T030 `tests/…/PurchasableCoursesTest.php` (جديد): **كلُّ خيارٍ يُعيدُه البابُ يمشي خلالَ `isPartyTo` الحقيقيّة، وكلُّ ما تقبلُه يظهرُ** — `SC-003` في الاتّجاهَين، هجاءُ `LeaderboardScopesTest`.

## Phase 7 · ⛔ أبوابُ الدافع

- [X] **T031** ⛔ `Policies/OrderPolicy::pay()`: فرعُ `granted_by`. **الزرُّ معروضٌ للنائبِ منذُ ٠٢٩ والبابُ يرفض.**
- [X] T032 حالتانِ في `CreditPurchaseGuardsTest`: **يسدّد** (الفرعُ الجديد) و**يرفعُ الإيصال** (فرعُ ٠٢٩) — متجاورتانِ كي لا يفترقا ثانيةً.
- [X] **T033** فهرسٌ فريدٌ على `credit_purchases.order_id` — لا فهرسَ عليه اليوم، و`Order::creditPurchase()` مُحمَّلٌ مسبقاً على كلِّ صفحةٍ من `‎/orders` ⇒ **مسحٌ كاملٌ لجدولٍ ينمو مع كلِّ بيعة**. ⚠️ يُفحَصُ المكرَّرُ أوّلاً؛ فإن وُجِدَ شُحِنَ العاديّ.

## Phase 8 · الواجهة

- [X] T034 `frontend/src/lib/billing.ts`: `beneficiaries()` و`purchasableCourses(studentUuid?)` و`student_uuid` على البابَين. ⚠️ مورِدٌ مفردٌ يُكتَبُ بلا `{data:…}`.
- [X] T035 `billing/purchase/page.tsx`: منتقي طالبٍ من البابِ الجديد، ثمّ كورساتُ المختار. ⛔ **لا ترشيحَ صلاحيّاتٍ في TypeScript** (`FR-018`).
- [X] T036 نفسُه: وصيٌّ بلا ابنٍ صالحٍ ⇒ جملةٌ وسببٌ ورابطٌ إلى «المرتبطون» (`FR-013`)، لا نموذجٌ فارغ.
- [X] T037 `billing/purchase/page.test.tsx`: الطالبُ لا يرى منتقياً · الوصيُّ يراه · وخياراتُه من الخادمِ لا من ترشيحٍ محلّيّ.

## Phase 9 · البوّابات

- [X] T038 `cd backend && ./vendor/bin/pint && ./vendor/bin/phpstan analyse`
- [X] T039 `php artisan migrate` ثمّ أعِدْ قياسَ `T001`
- [X] T040 `php vendor/bin/pest tests/Feature/Payments tests/Feature/Identity` — مرّةً واحدة
- [X] T041 `cd frontend && npx tsc --noEmit && npm test`

---

## التوازي

`T009`–`T011` · `T018`–`T020` · `T026` `T028` · `T034` `T037`. وما عداها متسلسلٌ بالملفّ.

## MVP

`Phase 2` + `Phase 3` + `Phase 4` — الخادمُ صحيحٌ والعيوبُ القائمةُ مغلقة. و`Phase 6`
تجعلُ الشاشةَ قابلةً للاستعمال.

## ⚠️ ما لا يُشحَنُ منفصلاً

`T012` و`T016` **يُشحَنانِ معاً**: إصلاحُ السقفِ وحدَه يحوِّلُ الطلبَ المهجورَ في `T016` من
إزعاجٍ إلى **قفلٍ دائمٍ** على شراءِ الابن.
