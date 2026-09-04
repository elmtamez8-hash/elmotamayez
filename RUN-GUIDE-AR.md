# دليل تشغيل مراجعة المنصة (Next.js + Laravel API + Docker)

## 1) التجهيز (مرة واحدة)

من جذر المستودع:

```bash
mkdir -p .claude/agents .claude/commands audit
cp platform-audit-kit/agents/*.md   .claude/agents/
cp platform-audit-kit/commands/*.md .claude/commands/
cp platform-audit-kit/AUDIT-PROMPT.md ./AUDIT-PROMPT.md
git checkout -b audit/full-review
```

ملاحظات:
- الوكلاء في `.claude/agents/` تخص المشروع، ولو حبيتهم في كل المشاريع حطهم في `~/.claude/agents/`.
- الأمر `/audit` بيتقرأ من `.claude/commands/audit.md`.
- افتح Claude Code وشغّل `/agents` للتأكد إن الستة ظاهرين.

## 2) الترتيب الموصى به

كل مرحلة في جلسة مستقلة (`/clear` بينهم) عشان الكونتكست ما يتلوّثش:

| # | المرحلة | الأمر | المخرج |
|---|---------|-------|--------|
| 0 | الاستطلاع | `/audit recon` | `audit/00-MAP.md` |
| 1 | الأمان + سيناريو الحجز | `/audit security` | `01-SECURITY.md` + `06-BOOKING-FLOW.md` |
| 2 | قاعدة البيانات | `/audit db` | `03-DATABASE.md` + `03-INDEXES.sql` |
| 3 | الفرونت والتعارضات | `/audit frontend` | `04-REACHABILITY.md` + `05-CONFLICTS.md` |
| 4 | الكلين كود | `/audit` ثم اطلب `clean-code-reviewer` | `02-CLEANCODE.md` |
| 5 | التقرير الموحد | `/audit report` | `audit/REPORT.md` |

لو مش عايز تستخدم الـ slash command، اكتب الطلب صراحة:

> Read AUDIT-PROMPT.md, then use the booking-flow-verifier sub agent to audit both
> booking flows. Read-only. Evidence with file:line. Write audit/06-BOOKING-FLOW.md.

## 3) بداية كل جلسة

الصق السطرين دول في أول رسالة:

> Read `AUDIT-PROMPT.md` and `audit/00-MAP.md` first.
> This session is READ-ONLY: findings only, no code changes, no refactors.

## 4) تشغيل مرحلة قاعدة البيانات مع داتابيز حية

الوكيل هيقترح فهارس، والتأكيد بيحتاج `EXPLAIN` على نسخة تطوير:

```bash
docker compose exec db mysql -u root -p <database> -e "EXPLAIN <query>\G"
docker compose exec db mysql -u root -p <database> -e "SHOW INDEX FROM enrollments;"
docker compose exec app php artisan route:list --path=api
```

مهم: `EXPLAIN` على نسخة فيها بيانات قريبة من الإنتاج، لأن الخطة بتتغير مع حجم الجدول.

## 5) بعد التقرير

1. اقرأ `audit/REPORT.md` وحدد الدفعة الأولى.
2. الموافقة تبقى صريحة ومحددة:
   > Implement findings SEC-03, BOOK-01 and BOOK-04 only. One commit per finding,
   > message prefixed with the finding ID. Do not touch anything else.
3. بعد كل دفعة: شغّل الاختبارات، راجع الـ diff، ثم انتقل للدفعة التالية.
4. الترتيب: الأمان وسلامة البيانات أولاً، ثم الأداء، ثم إعادة الهيكلة.

## 6) قواعد تحميك من مشاكل المراجعة الآلية

- **برانش منفصل** ولا تدمج قبل ما تقرأ الـ diff بنفسك.
- **ممنوع التعديل أثناء المراجعة.** لو الوكيل بدأ يعدّل، أوقفه فوراً.
- **اطلب الدليل.** أي ملاحظة من غير `file:line` تعتبر تخمين وتُرفض.
- **الصفحات اليتيمة تحتاج تأكيد بشري** قبل الحذف: ممكن تكون مستخدمة عبر رابط
  ديناميكي أو من تطبيق موبايل.
- **مؤشرات الأداء:** لا تضيف أي فهرس قبل ما تشوف `EXPLAIN` قبل وبعد.
- **سيناريوهات التزامن** (حجزين على نفس الموعد) تتأكد باختبار فعلي، مش بقراءة كود.

## 7) الوقت التقديري

مشروع متوسط (~40 endpoint، ~60 صفحة):
- الاستطلاع: 10–20 دقيقة
- الأمان + الحجز: 40–60 دقيقة
- قاعدة البيانات: 30–45 دقيقة
- الفرونت + التعارضات: 30–45 دقيقة
- الكلين كود: 30 دقيقة
- التقرير: 15 دقيقة
