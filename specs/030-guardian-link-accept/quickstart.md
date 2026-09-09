# Quickstart — `030-guardian-link-accept`

## قبلَ أيِّ شيء

```bash
cd backend
php artisan migrate            # ⚠️ لا migrate:fresh — القياسُ تحتَه
```

## SC-007 — صفرُ روابطَ تتغيّرُ حالتُها بالنشر

```bash
php artisan tinker --execute="print_r(DB::table('parent_student_relations')
  ->selectRaw('status, count(*) n')->groupBy('status')->pluck('n','status')->all());"
```

يُشغَّلُ **قبلَ** الهجرةِ وبعدَها. الرقمانِ متطابقان، أو الهجرةُ كتبَت ما لا يحقُّ لها.
(القياسُ يومَ ٢٠٢٦-٠٩-٠٨: `active 16 · revoked 101 · pending 0`.)

## SC-001 + SC-002 — المسارُ من طرفٍ إلى طرف، بلا تركيبةٍ تكتبُ الحالةَ بيدِها

هذا هو المقياسُ الوحيدُ الذي لا يُخدَع. كلُّ خطوةٍ تمرُّ من الإجراءِ الحقيقيّ:

```
1. حسابُ طالبٍ حقيقيّ   (last_workspace_id يبقى NULL — القاعدةُ المسجَّلةُ في CLAUDE.md)
2. الوصيُّ ← LinkGuardian(studentUuid: …)            ⇒  pending
3. childrenOf(الوصيّ, Payments)                       ⇒  فارغة ✔  (هذا هو عطبُ اليوم)
4. الطالبُ ← POST …/accept                            ⇒  200 · active
5. childrenOf(الوصيّ, Payments)                       ⇒  الطالبُ فيها ✔
6. PurchaseBeneficiary::resolve(الوصيّ, uuid الطالب)  ⇒  لا يرمي ✔  (SC-002 · يفتحُ 029)
```

**⚠️ لا `status => 'active'` في أيِّ تركيبةٍ من هذه الخطوات.** التركيبةُ التي تكتبُ الحالةَ
بيدِها تُنتِجُ طقماً أخضرَ فوقَ بياناتٍ لا تملكُها المنصّة — وهو بعينُه سببُ وجودِ هذه المرحلة.

## SC-003 — لا أحدَ غيرُ الطرفِ الآخرِ يُنشِّط

أربعةُ فاعلين على الصفِّ نفسِه، والمنتظَرُ `403` من كلٍّ منهم:

| الفاعل | لماذا هو في القائمة |
|---|---|
| **الوصيُّ** (صاحبُ الطلب) | لا يقبلُ نيابةً عمّن طلبَ منه |
| مدرّسٌ عندَه تسجيلٌ نشطٌ للطالب | يقرأُ الصفَّ بالسياسة، ولا يبتُّ فيه |
| طالبٌ أجنبيّ | ليس طرفاً |
| **super admin** | ⚠️ `Gate::before` يمرِّرُه فوقَ كلِّ سياسة. بلا هذا الفاعلِ الاختبارُ يقيسُ فرعاً آخر |

## SC-004 — يقرأُ **قبلَ** أن يقبل

```bash
curl -s -H "Authorization: Bearer $STUDENT" localhost:8000/api/v1/family/relations \
  | jq '.data[0] | {viewer_side, can_decide, guardian: .guardian.name, relation_type_label,
                    permissions: [.permissions[].label]}'
```

`guardian.name` غيرُ فارغ · `relation_type_label` · قائمةُ التسمياتِ العربيّة. الثلاثةُ معاً
أو القبولُ توقيعٌ على بياض.

## SC-005 — الوصيُّ لا يوسِّعُ نفسَه

علاقةٌ نشطةٌ بـ`["attendance"]` وحدَها:

```
PATCH  {"permissions":["attendance","payments"]}   بفاعلِ الوصيّ    ⇒ 422
PATCH  {"permissions":[]}                          بفاعلِ الوصيّ    ⇒ 200
PATCH  {"permissions":["attendance","payments"]}   بفاعلِ الطالب    ⇒ 200
```

## SC-006 — القبولُ المكرَّرُ لا يُحرِّكُ التاريخ

`POST …/accept` مرّتَين، و`accepted_at` في الجوابَينِ متطابق.

## الشاشة

```bash
cd frontend && npm test -- family
```

ثمّ بالعين: حسابُ طالبٍ ⇒ `‎/family` ⇒ قسمُ **«من يتابعني»** يحملُ **اسمَ الوصيِّ** لا اسمَ
الطالبِ نفسِه، وزرَّ «قبول» على المعلَّقِ و«قطع الارتباط» على النشط.

**⚠️ إن لم يظهرْ ما حُفِظ: `Ctrl+Shift+R`.** المتصفّحُ يقدّمُ القطعةَ القديمةَ وإعادةُ
تشغيلِ الخادمِ لا تمحوها — مقيسٌ في هذا المستودعِ ثلاثَ مرّات.

## البوّابات

```bash
cd backend && ./vendor/bin/pint && ./vendor/bin/phpstan analyse \
  && php vendor/bin/pest tests/Feature/Identity tests/Feature/Notifications
cd ../frontend && npx tsc --noEmit && npm test
```

**الطقمُ الكاملُ لا يُشغَّلُ محليّاً** — CI يفعل. ولا طقمانِ معاً.
