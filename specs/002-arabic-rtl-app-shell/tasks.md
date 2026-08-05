---
description: "Task list for 002-arabic-rtl-app-shell"
---

# Tasks: تعريب لوحة التطبيق وتوحيد نظام التصميم (Arabic RTL App Shell)

**Input**: `specs/002-arabic-rtl-app-shell/` — [spec.md](./spec.md) · [plan.md](./plan.md) ·
[research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) ·
[quickstart.md](./quickstart.md)

**Tests**: مطلوبة صراحةً. `NFR-003` يوجب توسيع اختبارات Playwright، وستة من معايير النجاح
التسعة لا تُثبَت إلا بفحص آلي (`SC-001`, `SC-002`, `SC-006`, `SC-007`, `SC-008`, `SC-009`).
اختبارات الوحدة **غير** مطلوبة — الدستور IV: «اختبارات الميزات هي شبكة الأمان الأساسية».

**Organization**: مجمَّعة بقصّة المستخدم. كل قصة قابلة للدمج وحدها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابل للتوازي — ملف مختلف، بلا تبعية على مهمّة غير مكتملة
- **[Story]**: `[US1]` · `[US2]` · `[US3]`

## Path Conventions

تطبيق ويب: `frontend/src/` · `frontend/e2e/` · `backend/lang/`. لا مجلد جذري جديد.

> **قاعدة بيئية سارية على كل مهمّة تشغّل بناءً**: **يُمنع** تشغيل `npm run build` أثناء عمل
> `npm run dev`. أوقف خادم التطوير أولاً.

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: ما يجب أن يوجد قبل أن تُكتب أي شاشة — الرمز المفقود ومجلد المكتبة وخطّ الأساس المقيس.

- [X] T001 قِس خطّ الأساس واحفظه في `specs/002-arabic-rtl-app-shell/baseline.txt` بتشغيل أوامر §٣ و§٤ من [quickstart.md](./quickstart.md) — المتوقّع: ٥٣٠ لوناً افتراضياً + ٥٧ `bg-white` + ١٩ صنفاً فيزيائياً. الرقم هو مقياس التقدّم، والملف يُحذف عند إغلاق المرحلة
- [X] T002 أضف `--color-surface-raised` إلى كتلة `@theme` (`#ffffff`) وإلى كتلة `[data-theme="dark"]` (`#1f2937`) في `frontend/src/app/globals.css` مع تعليق يشرح أنه يستبدل نمط `bg-white dark:bg-transparent`
- [X] T003 [P] أنشئ `frontend/src/components/ui/` بملف `index.ts` فارغ يُصدِّر لاحقاً — المجلد نقطة الاستيراد الوحيدة للمكتبة
- [X] T004 [P] أضف `frontend/e2e/.auth/` إلى `frontend/.gitignore` — `storageState` يحمل رمز مصادقة ولا يدخل المستودع

---

## Phase 2: Foundational (شرط مسبق حاجب)

**Purpose**: قلب الجذر إلى RTL. **كل** مهمّة بعده تُكتب في السياق النهائي؛ وقبله تُكتب مرتين.

**⚠️ حاجب**: لا مهمّة من أي قصّة تبدأ قبل اكتمال هذه المرحلة.

- [X] T005 أنشئ `frontend/src/app/layout.tsx` — تخطيط جذري وحيد يحمل `<html lang="ar" dir="rtl" suppressHydrationWarning className={cairo.variable}>`، وخط Cairo عبر `next/font/google` بأوزان `400/600/700`، وسكربت السمة المتزامن في `<head>`، ورابط التخطّي، و`<body className="min-h-screen bg-surface font-sans text-ink antialiased">`. المصدر المنقول: `frontend/src/app/(public)/layout.tsx:11-16,29-39,49-59`
- [X] T006 جرِّد `frontend/src/app/(public)/layout.tsx` من `<html>`/`<head>`/`<body>` ومن تعريف الخط وسكربت السمة ورابط التخطّي — يبقى `metadata` و`SiteHeader` و`<main id="main">` و`SiteFooter` و`FloatingActions` فقط
- [X] T007 جرِّد `frontend/src/app/(app)/layout.tsx` من `<html>`/`<body>` — يبقى `AuthProvider` و`metadata` معرَّباً (`title` و`description`)
- [X] T008 شغّل `npx tsc --noEmit` و`npm run build` من `frontend/` وتأكّد أن Next.js لا يشكو من تخطيط جذري مكرّر، وأن كل صفحة في المجموعتين تُقدَّم بـ`dir="rtl"`

**Checkpoint**: المنتج كلّه RTL عربي المستند. الشاشات ما زالت إنجليزية النصّ ورمادية اللون — وهذا متوقّع.

---

## Phase 3: User Story 1 — انتقال متّصل من الموقع العام إلى اللوحة (P1) 🎯 MVP

**Goal**: طالب يسجّل الدخول فتفتح اللوحة بنفس اللغة والاتجاه والخط والسمة، بلا انقطاع بصري ولا ومضة.

**Independent Test**: سجّل الدخول كطالب وتصفّح `/dashboard` · `/enrollments` · `/exams` ·
`/certificates` · `/orders` · `/settings` — اللغة والاتجاه والخط والسمة متّسقة، والشريط الجانبي يميناً.

### Tests for User Story 1

> اكتبها أولاً وتأكّد أنها **تسقط** قبل التنفيذ.

- [X] T009 [P] [US1] أنشئ `frontend/e2e/auth.setup.ts` — مشروع إعداد يصادق عبر `POST /api/v1/auth/login` بـ`student@example.com`/`password`، يكتب `auth_token` في `localStorage`، ويحفظ `storageState` في `e2e/.auth/user.json`
- [X] T010 [US1] أضف مشروع `setup` إلى `frontend/playwright.config.ts` واربط المشاريع الستة به بـ`dependencies: ["setup"]` و`storageState: "e2e/.auth/user.json"` (يعتمد على T009)
- [X] T011 [P] [US1] أنشئ `frontend/e2e/rtl.spec.ts` بحالة `lang/dir` تمرّ على الصفحات الستّ وتؤكّد `documentElement.lang === "ar"` و`dir === "rtl"` — **SC-001**
- [X] T012 [P] [US1] أضف إلى `frontend/e2e/rtl.spec.ts` حالة `flash`: في مشروع `desktop-dark`، اضبط `localStorage['theme']='dark'` قبل التنقّل والتقط أول إطار مرسوم وأكّد أن لون الخلفية ليس فاتحاً — **SC-007**
- [X] T013 [P] [US1] أضف إلى `frontend/e2e/rtl.spec.ts` حالة `overflow`: في مشروع `mobile-light`، أكّد `document.body.scrollWidth <= window.innerWidth` في كل صفحات اللوحة — **SC-008**

### Implementation for User Story 1

- [X] T014 [US1] أعد كتابة `frontend/src/app/(app)/(shell)/layout.tsx`: عرِّب `mainNav` و`adminNav` («لوحة التحكم» · «الكورسات» · «تعلّمي» · «الاختبارات» · «الشهادات» · «الطلبات» · «مساحات العمل» · «الأعضاء» · «الإعدادات» · «الإدارة» · «تسجيل الخروج» · «جارٍ التحميل…»)، واستبدل `fixed inset-y-0 left-0` بـ`fixed inset-y-0 start-0` و`ml-64` بـ`ms-64`، واستبدل `bg-gray-900`/`bg-indigo-600`/`text-gray-300`/`border-gray-200`/`bg-white` برموز `surface`/`primary`/`ink-muted`/`line`/`surface-raised`
- [X] T015 [US1] استُخرجت مسارات الـ`svg` من `(shell)/layout.tsx` إلى `frontend/src/components/icons/index.tsx`. **تصحيح على العقد**: الاتجاه يُحمَل في اسم الأيقونة (`ChevronStartIcon`) لا بصنف `rtl:-scale-x-100` — النمط القائم في المكتبة، وهو يجعل **FR-013** غير قابل للكسر بنيوياً. حُدِّث [contracts/design-tokens.md §٤](./contracts/design-tokens.md)
- [X] T016 [US1] أضف `ThemeToggle` إلى ترويسة `(shell)/layout.tsx` — يقرأ ويكتب `localStorage['theme']` نفسه، لا مفتاحاً ثانياً (**FR-009**)
- [X] T017 [P] [US1] استبدل ١٩ موضع `bg-white dark:bg-transparent` بـ`bg-surface-raised` في `frontend/src/components/marketplace/` و`frontend/src/app/(public)/`
- [X] T018 [P] [US1] صحّح الأصناف الفيزيائية الثلاثة الباقية في `(public)` — `mr-auto` في `frontend/src/components/marketplace/SiteHeader.tsx:41` وأختاها — إلى `me-auto`
- [X] T019 [US1] شغّل `npx playwright test e2e/rtl.spec.ts` وأصلح حتى تخضرّ الحالات الثلاث

**Checkpoint**: US1 مكتملة وقابلة للعرض. المنتج عربي الاتجاه والمظهر، ونصوص الشاشات لم تُعرَّب بعد.

---

## Phase 4: User Story 1 (تكملة) — تعريب نصوص الشاشات

**Purpose**: الشريحة الثانية من `US1`. مفصولة لأنها ٢٢ ملفاً متوازياً بالكامل، ودمجها في مهمّة واحدة يخفي التقدّم.

**كل مهمّة أدناه تعني ثلاثة أشياء في نفس الملف**: تعريب كل نصّ ظاهر · استبدال كل صنف لون
افتراضي برمز · استبدال كل صنف اتجاه فيزيائي بمنطقي.

- [X] T020 [P] [US1] `frontend/src/app/(app)/(shell)/dashboard/page.tsx`
- [X] T021 [P] [US1] `frontend/src/app/(app)/(shell)/enrollments/page.tsx`
- [X] T022 [P] [US1] `frontend/src/app/(app)/(shell)/exams/page.tsx`
- [X] T023 [P] [US1] `frontend/src/app/(app)/(shell)/exams/new/page.tsx`
- [X] T024 [P] [US1] `frontend/src/app/(app)/(shell)/exams/[uuid]/manage/page.tsx`
- [X] T025 [P] [US1] `frontend/src/app/(app)/(shell)/exams/[uuid]/take/page.tsx`
- [X] T026 [P] [US1] `frontend/src/app/(app)/(shell)/exams/[uuid]/result/page.tsx`
- [X] T027 [P] [US1] `frontend/src/app/(app)/(shell)/certificates/page.tsx`
- [X] T028 [P] [US1] `frontend/src/app/(app)/(shell)/orders/page.tsx`
- [X] T029 [P] [US1] `frontend/src/app/(app)/(shell)/settings/page.tsx`
- [X] T030 [P] [US1] `frontend/src/app/(app)/(shell)/members/page.tsx`
- [X] T031 [P] [US1] `frontend/src/app/(app)/(shell)/manage/courses/page.tsx`
- [X] T032 [P] [US1] `frontend/src/app/(app)/(shell)/manage/courses/new/page.tsx`
- [X] T033 [P] [US1] `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx`
- [X] T034 [P] [US1] `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/edit/page.tsx`
- [X] T035 [P] [US1] `frontend/src/app/(app)/(shell)/workspaces/page.tsx`
- [X] T036 [P] [US1] `frontend/src/app/(app)/(shell)/workspaces/new/page.tsx`
- [X] T037 [P] [US1] `frontend/src/app/(app)/(shell)/workspaces/[uuid]/edit/page.tsx`
- [X] T038 [P] [US1] `frontend/src/app/(app)/login/page.tsx`
- [X] T039 [P] [US1] `frontend/src/app/(app)/register/page.tsx`
- [X] T040 [P] [US1] `frontend/src/app/(app)/invitations/[token]/page.tsx`
- [X] T041 [P] [US1] `frontend/src/app/(app)/certificates/verify/[code]/page.tsx`
- [X] T042 [US1] أضف حالة `latin` إلى `frontend/e2e/rtl.spec.ts` بثابت `ALLOWED_LATIN` معلَن — راجع [research.md قرار R6](./research.md) — تمرّ على كل صفحات اللوحة وتسقط على أي بقيّة لاتينية — **SC-002**
- [X] T043 [US1] شغّل أمرَي §٣ و§٤ من [quickstart.md](./quickstart.md) وأكّد **صفر** نتيجة من الأربعة — **SC-003** و**FR-015** (يعتمد على T020–T041)

**Checkpoint**: `US1` مكتملة. `SC-001` · `SC-002` · `SC-003` · `SC-007` · `SC-008` خضراء.

---

## Phase 5: User Story 2 — رسائل النظام والأخطاء بالعربية (P2)

**Goal**: كل رسالة يقرأها المستخدم عربية ومفهومة — تحقّق النماذج، وأخطاء الخادم، وحالات الفراغ.

**Independent Test**: أرسل نماذج ناقصة في ثلاث شاشات، وأوقف الخلفية وأعد المحاولة — لا نصّ إنجليزي ولا استثناء تقني.

### Backend — الترجمة من المصدر

> **مخالفة موثّقة لـ`NFR-001`** — التبرير في [plan.md § Complexity Tracking](./plan.md#complexity-tracking).

- [X] T044 [P] [US2] أنشئ `backend/lang/ar/validation.php` — نسخة عربية من رسائل Laravel القياسية
- [X] T045 [P] [US2] أنشئ `backend/lang/ar/passwords.php` و`backend/lang/ar/auth.php`
- [X] T046 [US2] استخرج كل أسماء الحقول من `backend/app/Modules/*/Requests/*.php` واملأ مصفوفة `attributes` في `validation.php` بمسمّى عربي لكل واحد — حقل بلا مدخل يظهر باسمه البرمجي، وهو عطل صامت في **FR-016** (يعتمد على T044)
- [X] T047 [US2] اضبط `APP_LOCALE=ar` و`APP_FALLBACK_LOCALE=ar` في `backend/.env.example` و`docker/` و`backend/phpunit.xml` — و**اترك** `APP_FAKER_LOCALE=en_US`
- [X] T048 [US2] ابحث عن نصوص التحقّق الحرفية داخل `backend/app/Modules/*/Requests/` و`Rules/` وانقلها إلى مفاتيح تحت `backend/lang/ar/`
- [X] T049 [US2] شغّل `php vendor/bin/pest` وأصلح كل اختبار يؤكّد نصّ رسالة تحقّق إنجليزياً — أعد كتابته ليؤكّد **مفتاح الحقل** في `errors` لا نصّ الرسالة. **يُمنع** `skip` أو استثناء لغة في بيئة الاختبار (يعتمد على T047)
- [X] T050 [US2] شغّل `./vendor/bin/pint --test` و`./vendor/bin/phpstan analyse` وأكّد خضرتهما

### Frontend — ما ليس خطأ تحقّق

- [X] - [ ] T051 [P] [US2] أنشئ `frontend/src/lib/errors.ts` بدالة `userMessage(err: unknown): string` وخريطة الحالات العشر من [contracts/error-messages.md §٣](./contracts/error-messages.md)
- [X] - [ ] T052 [US2] استبدل كل عرض لـ`err.message` الخام في `frontend/src/` باستدعاء `userMessage(err)` — **يُمنع** أن يصل نصّ استثناء إلى الشاشة (**FR-017**) (يعتمد على T051)
- [X] - [ ] T053 [US2] وصِّل `fieldErrors()` من `frontend/src/lib/api.ts:96` بكل نموذج في اللوحة بحيث تظهر رسالة كل حقل أسفله، وما لا يطابق حقلاً في التنبيه أعلى النموذج

### حالات الفراغ والتحميل والخطأ

- [X] - [ ] T054 [P] [US2] `dashboard` — الحالات الثلاث بنصوص [contracts/error-messages.md §٥](./contracts/error-messages.md)
- [X] - [ ] T055 [P] [US2] `enrollments` — الحالات الثلاث + تمييز «لا نتائج للتصفية» عن «لا بيانات»
- [X] - [ ] T056 [P] [US2] `exams` — الحالات الثلاث
- [X] - [ ] T057 [P] [US2] `certificates` — الحالات الثلاث
- [X] - [ ] T058 [P] [US2] `orders` — الحالات الثلاث
- [X] - [ ] T059 [P] [US2] `members` — الحالات الثلاث
- [X] T060 [P] [US2] `workspaces` — الحالات الثلاث
- [X] T061 [P] [US2] `manage/courses` — الحالات الثلاث + تمييز التصفية
- [X] T062 [US2] نفِّذ §٥ و§٦ من [quickstart.md](./quickstart.md) يدوياً وأكّد كل بند — **SC-005**

**Checkpoint**: `US1` و`US2` تعملان مستقلّتين. البوابات الأربع خضراء.

---

## Phase 6: User Story 3 — مكتبة مكوّنات واحدة للمنصة (P3)

**Goal**: كاتب شاشة في مرحلة قادمة يجد مكوّنات جاهزة معرّبة، فلا يخترع نمطاً بصرياً ثالثاً.

**Independent Test**: كل شاشة في اللوحة تستهلك المكتبة، وحذف أي نمط مكرّر لا يكسر صفحة.

> **لماذا أخيراً**: النمط المشترك لا يُعرَف قبل تعريب الشاشات وتوحيد ألوانها. مكتبة تُستخرَج
> أولاً تُصمَّم على تخمين — [research.md قرار R8](./research.md).

### النقل من `marketplace/`

- [X] T063 [P] [US3] انقل `Pagination.tsx` إلى `frontend/src/components/ui/` وحدِّث مستوردِيه واحذف الأصل
- [X] T064 [P] [US3] انقل `PhoneInput.tsx` إلى `frontend/src/components/ui/` وحدِّث مستوردِيه واحذف الأصل
- [X] T065 [P] [US3] انقل `ThemeToggle.tsx` إلى `frontend/src/components/ui/` وحدِّث مستوردِيه واحذف الأصل
- [X] T066 [P] [US3] انقل `states/{LoadingSkeleton,EmptyState,ErrorState}.tsx` إلى `frontend/src/components/ui/states/` وحدِّث مستوردِيها واحذف الأصول

### الاستخراج

> كل مكوّن **يجب** أن يُستهلَك في شاشة حقيقية قبل الدمج. **يُمنع** إضافة مكوّن لحاجة متوقّعة.

- [X] T067 [US3] أنشئ `frontend/src/components/ui/Button.tsx` من `SubmitButton.tsx` بالواجهة في [contracts/ui-components.md §١](./contracts/ui-components.md) — بلا `className` حرّ — واحذف `SubmitButton.tsx`
- [X] T068 [P] [US3] أنشئ `frontend/src/components/ui/Field.tsx` — التسمية والوصف ورسالة الخطأ مربوطة بـ`aria-describedby` **و**`aria-invalid` معاً
- [X] T069 [P] [US3] أنشئ `frontend/src/components/ui/Input.tsx` و`Textarea.tsx` — مع `inputMode` للرقمي و`<bdi>` للرقم الدولي
- [X] T070 [P] [US3] أنشئ `frontend/src/components/ui/Select.tsx`
- [X] T071 [P] [US3] أنشئ `frontend/src/components/ui/Card.tsx` و`Badge.tsx` و`Alert.tsx` — كل `tone` بأيقونة ونصّ، لا بلون وحده
- [X] T072 [US3] أنشئ `frontend/src/components/ui/Table.tsx` بواجهة `Column<T>` — ترتيب الأعمدة من البداية بلا عكس يدوي، وتمرير أفقي داخل غلافه، و`caption` إلزامية، و`state` يستهلك الحالات الثلاث (يعتمد على T066)
- [X] T073 [US3] أنشئ `frontend/src/components/ui/Modal.tsx` — تركيز محبوس، `Esc` يغلق، `aria-modal`، وإعادة التركيز إلى المُطلِق عند الإغلاق

### الاستهلاك

- [X] T074 [US3] بدّل كل زر في `frontend/src/app/(app)/` إلى `ui/Button` واحذف الأنماط المكرّرة (يعتمد على T067)
- [X] T075 [US3] بدّل كل حقل ونموذج في `frontend/src/app/(app)/` إلى `ui/Field` + `ui/Input`/`Select`/`Textarea` (يعتمد على T068–T070)
- [X] T076 [US3] بدّل كل جدول وقائمة في `frontend/src/app/(app)/` إلى `ui/Table` (يعتمد على T072)
- [X] T077 [US3] بدّل ما ينطبق في `frontend/src/components/marketplace/` و`frontend/src/app/(public)/` إلى مكوّنات `ui/` — المكتبة تخدم المنطقتين أو ليست مشتركة
- [X] T078 [US3] أكّد صفر ملف مكرّر وصفر استيراد من الموضع القديم بأمر §٩ من [quickstart.md](./quickstart.md) — **SC-004** و**FR-020**

**Checkpoint**: القصص الثلاث مكتملة ومستقلّة.

---

## Phase 7: Polish & Cross-Cutting

- [X] T079 وسّع `frontend/e2e/accessibility.spec.ts` بصفحات اللوحة الثماني عشرة على المصفوفة الكاملة (٣ عروض × سمتين) — **SC-006**
- [X] T080 شغّل `npm run test:e2e` وأصلح كل انتهاك axe من فئة `serious`/`critical`. **أوقف `npm run dev` أولاً**
- [X] T081 [P] راجع مؤشّر التركيز يدوياً: من `/dashboard` اضغط `Tab` من أعلى الصفحة — أول توقّف «تخطَّ إلى المحتوى الرئيسي»، ثم مؤشّر ظاهر على كل عنصر، وترتيب يتبع البصر من اليمين لليسار
- [X] T082 [P] راجع الأيقونات بصرياً: أسهم الترقيم تنعكس، وعدسة البحث ومثلّث التشغيل لا ينعكسان — **FR-012** و**FR-013**
- [X] T083 [P] حدّث `CLAUDE.md` و`AGENTS.md`: التخطيط الجذري صار واحداً، والمنتج كلّه RTL، وعقد الرموز يمنع القيم الحرفية، و`components/ui/` هي مصدر المكوّنات المشتركة
- [X] T084 [P] حدّث `docs/roadmap.md` بحالة 002 وبأن المراحل ٠٠٣–٠١٥ تستهلك `components/ui/` ولا تحتاج تعريباً لاحقاً
- [X] T085 احذف `specs/002-arabic-rtl-app-shell/baseline.txt` — خدم غرضه
- [X] T086 نفّذ [quickstart.md](./quickstart.md) كاملاً من §٠ إلى §١٠ وأكّد بنود قائمة القبول التسعة

---

## Dependencies & Execution Order

### Phase Dependencies

```
Setup (1) ──► Foundational (2) ──┬──► US1 (3) ──► US1 تكملة (4) ──► Polish (7)
                                 ├──► US2 (5) ─────────────────────►
                                 └──► US3 (6) ─────────────────────►
```

- **Setup**: بلا تبعية
- **Foundational**: يحجب كل شيء. T005 هو المفتاح — قبله كل تعريب يُكتب في سياق LTR ثم يُعاد
- **US1**: بعد Foundational — لا تعتمد على قصّة أخرى
- **US2**: بعد Foundational تقنياً، لكن نصوص الأخطاء تُقرأ في شاشات معرّبة — تُنفَّذ بعد US1 عملياً
- **US3**: بعد US1 **بالضرورة لا بالتفضيل** — المكتبة تُستخرَج من نمط معلوم لا متوقّع

### داخل القصص

- **US1**: T005–T008 (جذر) → T009–T013 (اختبارات تسقط) → T014–T018 (هيكل) → T020–T041 (صفحات، متوازية) → T042–T043 (تحقّق)
- **US2**: T044–T047 (خلفية) → T049–T050 (بوابات) ‖ T051–T053 (واجهة) → T054–T061 (حالات، متوازية) → T062
- **US3**: T063–T066 (نقل، متوازي) → T067–T073 (استخراج) → T074–T077 (استهلاك) → T078

### Parallel Opportunities

| الدفعة | المهام | العدد |
|---|---|---|
| تعريب الصفحات | T020–T041 | ٢٢ ملفاً مستقلاً |
| حالات القوائم | T054–T061 | ٨ |
| نقل المكوّنات | T063–T066 | ٤ |
| استخراج المكوّنات المستقلّة | T068–T071 | ٤ |
| اختبارات RTL | T011 · T012 · T013 | ٣ |
| التوثيق | T083 · T084 | ٢ |

---

## Parallel Example: تعريب الصفحات (Phase 4)

```bash
# ٢٢ ملفاً لا يتقاطع أحدها مع الآخر — كلٌّ يُعرَّب ويُلوَّن ويُصحَّح اتجاهه وحده
Task: "T020 dashboard/page.tsx"
Task: "T021 enrollments/page.tsx"
Task: "T022 exams/page.tsx"
…
Task: "T041 certificates/verify/[code]/page.tsx"

# ثم — تسلسلياً، لأنها تقيس الحصيلة
Task: "T043 أكّد صفر لون افتراضي وصفر اتجاه فيزيائي"
```

---

## Implementation Strategy

### MVP (US1 وحدها)

1. Phase 1 → Phase 2 → Phase 3 → Phase 4
2. **قف وتحقّق**: §١ و§٢ و§٣ و§٨ من quickstart
3. قابل للعرض على مستخدم حقيقي — منتج عربي متّسق

### Incremental

| الشريحة | المخرَج | البوابة |
|---|---|---|
| Setup + Foundational | المنتج كلّه RTL | `tsc` + `build` |
| **+ US1** | **MVP — منتج عربي متّسق** | SC-001·002·003·007·008 |
| + US2 | لا رسالة إنجليزية ولا طريق مسدود | SC-005 + `pest` |
| + US3 | مكتبة واحدة للمراحل القادمة | SC-004 |
| + Polish | إمكانية وصول مُثبَتة | SC-006 + SC-009 |

### فريق متعدّد

- Setup + Foundational معاً (T005 ملف واحد — لا يُوزَّع)
- ثم: مطوّر أ على US1 (T014–T041) · مطوّر ب على US2 الخلفية (T044–T050) · مطوّر ج ينتظر US1 ليبدأ US3
- T044–T050 المسار الوحيد الذي لا يلمس `frontend/` — يجري متوازياً مع US1 بلا تعارض

---

## Notes

- **٨٦ مهمّة** · US1: ٣٥ · US2: ١٩ · US3: ١٦ · بنية وصقل: ١٦
- `[P]` = ملف مختلف بلا تبعية معلّقة
- كل مهمّة تحمل مسار ملفها بالضبط
- التزم بالمرور على الاختبارات في T009–T013 قبل التنفيذ — تسقط أولاً
- **T005 هي المهمّة الحاجبة الوحيدة الحقيقية**. تأخيرها يعني كتابة كل شيء مرتين
- **T049 هي المخاطرة المجهولة**: عدد اختبارات `pest` التي تؤكّد نصوصاً إنجليزية غير معلوم قبل تشغيل T047. لو كان كبيراً، هو أكبر من مهمّة واحدة ويُقسَّم عندها لا قبلها

---

## انحرافات عن الخطة — مسجَّلة لا مسكوت عنها

ثلاثة قرارات خالفت `research.md` أو `contracts/` أثناء التنفيذ. كلها مُطبَّقة والعقود
مُحدَّثة لتطابق ما شُحِن.

### ١ — الاستخراج سُحِب من المرحلة ٦ إلى المرحلة ٤

`research.md` قرار R8 أخّر `components/ui/` إلى الأخير بحجّة أن «النمط المشترك لا يُعرَف
قبل تعريب الشاشات». بعد ثلاث صفحات كان النمط قد ظهر حرفياً — زرّ أساسي وزرّ محدَّد وبطاقة
وشارة — فبناء المكتبة أولاً وفَّر كتابة ٢٢ صفحة مرتين. الحجّة كانت صحيحة، وشرطها تحقّق
مبكراً عمّا قدّرت.

### ٢ — اتجاه الأيقونات بالاسم لا بقلب CSS

`contracts/design-tokens.md §٤` نصّ على `rtl:-scale-x-100` على قائمة معلَنة. المكتبة
القائمة تحلّها بأيقونات دلالية (`ChevronStartIcon` = سهم يمين)، وهي أفضل: المنتج أحادي
الاتجاه فلا شيء يُقلَب، و`FR-013` يصير غير قابل للكسر بنيوياً لأنه لا توجد قاعدة قد تلتقط
عدسة البحث. **حُدِّث العقد.**

### ٣ — مخالفة `NFR-001` ثانية: تسمية محدِّدات المعدّل

**لم تكن في الخطة.** كشفها تشغيل الـe2e: `ThrottleRequests::resolveRequestSignature()`
يبني مفتاحه من `domain|ip` **بلا المسار**، فكل `throttle:N,1` يتشارك عدّاداً واحداً لكل
زائر ويفوز الأصرم. الأثر الإنتاجي: **زائر يتصفّح خمس صفحات في السوق يُمنَع من تسجيل الدخول**.

الإصلاح في `AppServiceProvider::registerRateLimiters()`: ثلاثة محدِّدات مسمّاة
(`auth` · `registration` · `public`)، والمسارات تشير إليها بالاسم. محدِّد `auth` يُقيّد
بالبريد إلى جانب الـIP فلا يقفل مكتبٌ خلف NAT واحد حساباتِه على نفسه.

هذه مخالفة ثانية لـ`NFR-001` تُضاف إلى الأولى الموثّقة في
[plan.md § Complexity Tracking](./plan.md#complexity-tracking). مبرّرها أنها **عطل قائم**
لا تحسين: البند يمنع تعديل المنطق «ضمن هذه المرحلة»، والبوابة الرابعة (اختبارات Playwright)
لا تخضرّ بدونها.

### ملاحظة تشغيلية

`php artisan cache:clear` **لم يفرغ** جدول الـcache فعلياً في هذا المستودع؛ تصفير المحدِّد
يدوياً يحتاج `DB::table('cache')->delete()`.

### ما لم يُبنَ

`Modal` (T073) — لم يُستهلَك في شاشة واحدة. تأكيدات الحذف نُفِّذت بنقرتين في مكانها،
وهو أبسط ولا يحتاج حبس تركيز. عقد المكتبة يمنع إضافة مكوّن لحاجة متوقّعة، فبقي غير مبنيّ
حتى تظهر شاشة تحتاجه فعلاً.
