# Implementation Plan: الطالبُ يقبلُ وصيَّه (Guardian Link Accept)

**Branch**: `030-guardian-link-accept` | **Date**: 2026-09-08 | **Spec**: [spec.md](./spec.md)

**Input**: `specs/030-guardian-link-accept/spec.md`

## Summary

الرابطُ الذي يُسمّي حساباً يولَدُ `pending` ولا شيءَ في `app/` يستطيعُ تنشيطَه. هذه المرحلةُ
تُضيفُ **البتَّ**: مسارٌ واحدٌ للقبول، وسِجلُّ **من طلبَ ومن بتَّ ومتى**، وشاشةُ `‎/family`
تُقرَأُ من جهةِ الطالبِ لا من جهةِ الوصيِّ وحدَها، وحارسٌ يمنعُ الوصيَّ من توسيعِ صلاحيّاتِ نفسِه.

**المدخلُ التقنيُّ في سطر**: ثلاثةُ أعمدةٍ على العلاقةِ القائمة، ودالّةٌ واحدةٌ
(`decidableBy`) يقرؤُها البابُ والزرُّ والإجراء — لا جدولَ جديداً، ولا حالةً رابعةً، ولا
شاشةً ثالثة.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript (Next.js 15 App Router)

**Primary Dependencies**: لا شيءَ جديد. الإشعاراتُ تمرُّ من `DispatchNotification` القائم.

**Storage**: MySQL (SQLite محليّاً/اختباريّاً) — جدولٌ واحدٌ قائمٌ `parent_student_relations`
يكتسبُ ثلاثةَ أعمدةٍ ويتّسعُ فهرسُه الفريد.

**Testing**: Pest (Feature) · vitest (مكوّنات)

**Target Platform**: خادمٌ + متصفّح

**Project Type**: Web (backend/ + frontend/)

**Performance Goals**: لا قراءةَ جديدةً في مسارٍ ساخن. `‎/family/relations` تبقى استعلاماً
واحداً بتحميلٍ مسبقٍ للطرفَين.

**Constraints**: **لا ردمَ للحالة** (FR-011 · SC-007). الهجرةُ **لا تكتبُ صفّاً واحداً**.

**Scale/Scope**: قاعدةُ التطويرِ اليوم: `active 16` · `revoked 101` · **`pending 0`** —
مقيسٌ ٢٠٢٦-٠٩-٠٨، وهو ما يجعلُ SC-007 صحيحاً بالبناءِ لا بالوعد.

## Constitution Check

| المبدأ | الحكم | لماذا |
|---|---|---|
| **I · عزلُ المستأجرين** | ✅ | `parent_student_relations` **مملوكٌ للمنصّةِ** بلا `workspace_id`، ويبقى كذلك. الحارسُ هو `ParentStudentRelationPolicy` وحدَه، ويكتسبُ ذراعاً رابعة (`accept`). لا `BelongsToWorkspace` يُضاف — إضافتُه تُضاعِفُ الأسرةَ الواحدةَ لكلِّ مدرّس. |
| **II · المنطقُ في `Actions/`** | ✅ | `AcceptRelation` إجراءٌ جديد؛ و`RevokeRelation` و`UpdateRelationPermissions` يكتسبانِ **الفاعل**. وقاعدةُ «التوسيعُ للطالبِ وحدَه» في الإجراءِ لا في الطلب. |
| **III · حدودُ الوحدات** | ✅ | القراءةُ تبقى خلفَ `GuardianDirectory`. الإشعارُ حدثٌ من `Identity` يلتقطُه `Notifications` — لا استدعاءَ عبرَ الحدّ. |
| **IV · الاختبارُ شبكةُ الأمان** | ✅ | حالةُ SC-002 تُبنى **بلا `status => 'active'` في أيِّ تركيبة**: `LinkGuardian` ← `accept` ← `childrenOf()`. وهي المقياسُ الوحيدُ الذي لا يُخدَع. |
| **V · لا أسرارَ في المستودع** | ✅ | لا مفتاحَ ولا اعتماد. |

**النتيجة: مرورٌ كامل. لا بندَ في `Complexity Tracking`.**

## Project Structure

### Documentation (this feature)

```text
specs/030-guardian-link-accept/
├── spec.md
├── plan.md              ← هذا الملف
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/http.md
├── checklists/requirements.md
└── tasks.md             (‎/speckit-tasks)
```

### Source Code

```text
backend/app/Modules/Identity/
├── Actions/AcceptRelation.php                    (جديد · تحديثٌ شرطيّ · يستدعي DispatchNotification)
├── Actions/LinkGuardian.php                      (⛔ حارسُ Parent · requested_by · alreadyLinked · إشعار)
├── Actions/RegisterStudent.php                   (requested_by · firstOrCreate مقيَّدة · actionUrl · تعليقٌ بائت)
├── Actions/RevokeRelation.php                    (live_slot — بلا تغييرِ بصمة · إشعارُ البتّ)
├── Actions/UpdateRelationPermissions.php         (فاعلٌ · طرفيّةٌ ثمّ اتّجاه)
├── Models/ParentStudentRelation.php              (⛔ $fillable · casts · @property · decidableBy)
├── Policies/ParentStudentRelationPolicy.php      (accept ⇒ decidableBy)
├── Http/Controllers/FamilyController.php         (accept · ->load على الخمسة)
├── Http/Resources/ParentStudentRelationResource.php  (viewer_side · can_decide · accepted_at)
├── Database/Migrations/…_add_decision_to_parent_student_relations.php
└── routes/api.php

backend/app/Providers/AppServiceProvider.php      (معدَّلُ family-link — حدّان)

backend/app/Modules/Notifications/
├── Support/NotificationType.php                  (نوعانِ جديدان)
├── Support/NotificationCategory.php              (⛔ وإلّا احمرَّ البناء)
└── Database/Migrations/…_seed_guardian_link_templates.php   (ردمُ القالب)

backend/database/seeders/NotificationTemplateSeeder.php

backend/database/factories/Modules/Identity/ParentStudentRelationFactory.php  (requested_by)

frontend/src/
├── app/(app)/(shell)/family/page.tsx             (قسمانِ · RelationRow · ConfirmButton · محرِّرُ صلاحيّات)
├── app/(app)/(shell)/family/page.test.tsx        (جديد — الملفُّ بلا اختبارٍ اليوم)
├── lib/notifications.ts                          (accept · الحقولُ الثلاثةُ · حقلا ٠٢٢ البائتان)
├── lib/types.ts                                  (ChildLink — النسخةُ الثانيةُ لنفسِ الحمولة)
├── lib/panel-nav.tsx                             (guardian في جمهورِ /orders و/billing — ارتدادُ ٠٢٩)
└── lib/notification-links.test.ts                (/family في قائمةِ الوجهات)
```

**Structure Decision**: الوحدةُ القائمةُ `Identity` تحملُ كلَّ شيءٍ عدا القالبَ والإشعار.
لا وحدةَ جديدة.

## Phase 0 · Research

انظر [research.md](./research.md) — **خمسَ عشرةَ** نقطةً محسومة (`R1`…`R15`)، صفرُ
`NEEDS CLARIFICATION`. و`R11` و`R13` و`R14` **كتبَتْها المراجعةُ المتوازيةُ فوقَ خطّتي
الأولى**، وكلٌّ منها كان سيُشحَنُ عطباً.

## Phase 1 · Design

- [data-model.md](./data-model.md) — ثلاثةُ أعمدةٍ، وفهرسٌ فريدٌ **يتّسعُ ولا يُحذَف**.
- [contracts/http.md](./contracts/http.md) — مسارٌ واحدٌ جديد، وثلاثةُ حقولٍ في الحمولة.
- [quickstart.md](./quickstart.md) — كيف يُقاسُ كلُّ `SC` بيدَيك.

## Complexity Tracking

*لا مخالفات.*
