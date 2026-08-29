<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Role;

/**
 * Arabic for a permission name, composed rather than listed.
 *
 * ⚠️ A MAP OF SEVENTY-TWO SENTENCES WOULD BE SEVENTY-THREE THE DAY AFTER, and
 * the seventy-third would render as `sessions.freeze.manage` on a screen that is
 * Arabic-only. Permission names in this product are `{subject}.{action}` by
 * construction, so twenty nouns and thirty actions cover every one of them —
 * including the ones nobody has written yet. A name whose parts are both unknown
 * falls back to the name itself, which is ugly and readable, rather than to a
 * blank, which is a tick box for something nobody can identify.
 *
 * The exceptions get an entry in {@see self::SPECIAL}, and there are five: the
 * ones whose Arabic is a phrase, not a noun and a verb.
 */
final class PermissionLabels
{
    /** @var array<string, string> */
    private const SUBJECTS = [
        'members' => 'الأعضاء',
        'roles' => 'الأدوار',
        'courses' => 'الكورسات',
        'lessons' => 'الدروس',
        'enrollments' => 'التسجيلات',
        'exams' => 'الاختبارات',
        'questions' => 'الأسئلة',
        'attempts' => 'المحاولات',
        'certificates' => 'الشهادات',
        'orders' => 'الطلبات',
        'payments' => 'المدفوعات',
        'cms' => 'المحتوى',
        'analytics' => 'التحليلات',
        'settings' => 'الإعدادات',
        'marketplace' => 'السوق',
        'sessions' => 'الحصص',
        'attendance' => 'الحضور',
        'freeze' => 'فترات التجميد',
        'notifications' => 'الإشعارات',
        'relations' => 'أولياء الأمور',
        'settlement' => 'تسوية المدرّس',
        'billing' => 'الأرصدة والفوترة',
        // Spec 008.
        'bank' => 'بنك الأسئلة',
        'grading' => 'التصحيح',
        'assignments' => 'الواجبات',
        'submissions' => 'تسليمات الواجبات',
        'accommodations' => 'تسهيلات التقييم',
        'unlock_rules' => 'شرط فتح الحصة',
        'rewards' => 'متجر المكافآت',
        'redemptions' => 'طلبات الاستبدال',
        // Spec 013. Platform-level, so it never reaches the role screen — but a
        // name is rendered wherever a permission is shown, and an Arabic-only
        // panel showing `compliance.holds.manage` is the defect this file exists
        // to prevent.
        'compliance' => 'الامتثال وحماية البيانات',
        // Spec 010. "محادثات الطلاب" and not "المحادثات": the tick box grants
        // replying in SOMEBODY ELSE'S conversation, and a label that reads as
        // "the chat" is one an owner grants without noticing whose.
        'chat' => 'محادثات الطلاب',
        // Spec 011.
        'plans' => 'باقات الاشتراك',
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        'view' => 'عرض',
        'view.all' => 'عرض الكل',
        'view.own' => 'عرض ما يخصّه',
        'view.student' => 'عرض بيانات الطالب',
        'create' => 'إنشاء',
        'create.manual' => 'إنشاء يدويّ',
        'update' => 'تعديل',
        'delete' => 'حذف',
        'remove' => 'إزالة',
        'invite' => 'دعوة',
        'publish' => 'نشر',
        'archive' => 'أرشفة',
        'manage' => 'إدارة',
        'submit' => 'تسليم',
        'approve' => 'اعتماد',
        'reject' => 'رفض',
        'regenerate' => 'إعادة إصدار',
        'host' => 'استضافة',
        'override' => 'تعديل يدويّ',
        'logs.view' => 'عرض السجلّ',
        'templates.manage' => 'إدارة القوالب',
        'audit.view' => 'عرض سجلّ التدقيق',
        'balance.view' => 'عرض الرصيد',
        'settings.manage' => 'إدارة الإعدادات',
        'purchase.approve' => 'اعتماد الشراء',
        'credits.adjust' => 'تسوية الأرصدة',
        'limit.manage' => 'إدارة الحدّ الائتماني',
        'exam_mode.manage' => 'إدارة وضع الامتحانات',
        'packages.manage' => 'إدارة الحزم',
        'pricing.manage' => 'إدارة التسعير',
        'collection.view' => 'عرض التحصيل',
        'rate.request' => 'طلب السعر',
        'rate.approve' => 'اعتماد السعر',
        'statement.view' => 'عرض الكشف',
        'period.manage' => 'إدارة الفترات',
        'payout.execute' => 'تنفيذ الصرف',
        'participation.manage' => 'إدارة المشاركة',
        'reviews.moderate' => 'الإشراف على التقييمات',
        'complaints.manage' => 'إدارة الشكاوى',
        'teachers.review' => 'مراجعة المدرّسين',
        'teachers.approve' => 'اعتماد المدرّسين',
        'teachers.suspend' => 'إيقاف مدرّس',
        'progress.complete.own' => 'إكمال تقدّمه',
        // Spec 008.
        'perform' => 'تنفيذ',
        'revise' => 'تعديل بسببٍ مسجَّل',
        'grade' => 'اعتماد الدرجة',
        'fulfill' => 'تنفيذ الطلب',
        // Spec 013.
        'requests.execute' => 'تنفيذ طلبات الحقوق',
        'registry.manage' => 'إدارة الأصناف والمعالِجين',
        'holds.manage' => 'إدارة التعليق القانونيّ',
        'offboarding.execute' => 'تنفيذ خروج المدرّس',
        'breaches.manage' => 'إدارة بلاغات التسريب',
        // Spec 010.
        'reply' => 'الردّ',
    ];

    /** The handful whose Arabic is not a noun and a verb. @var array<string, string> */
    private const SPECIAL = [
        'lessons.progress.complete.own' => 'إكمال درسٍ لنفسه',
        'attendance.override' => 'تعديل علامة حضور بسببٍ مسجَّل',
        'notifications.templates.manage' => 'إدارة قوالب الرسائل',
        'settlement.audit.view' => 'عرض تدقيق أجور المدرّسين',
        'billing.audit.view' => 'عرض سجلّ التدقيق المالي',
        'analytics.cross_teacher.view' => 'عرض التحليل عابراً للمدرّسين',
        /*
        | Spec 011. Three-part names, which the composer cannot reach — and each
        | label says WHOSE thing it is, because two of the four are platform
        | decisions that must never read as ordinary workspace settings.
        */
        'store.items.manage' => 'إدارة منتجات المتجر',
        'store.shipments.manage' => 'إدارة شحنات المتجر',
        // "باقات الاشتراك — إدارة" would read as including the price. It does
        // not: the platform sets that (011 · Q4), and the teacher writes the
        // duration and what the plan covers.
        'plans.manage' => 'إدارة الباقات (المدّة والتغطية)',
        // Platform-level. The label names the payer, because a coupon is spent
        // out of the platform's commission and never out of the teacher's share.
        'billing.coupons.manage' => 'إنشاء كوبونات المنصّة',
        'flags.manage' => 'مفاتيح مزايا المنصّة',
        // "عرض بيانات الطالب — التقدّم" would read as a general licence, and this
        // permission is never sufficient alone: the route also demands an active
        // enrollment in the reader's own workspace. The label says both halves.
        'progress.view.student' => 'عرض تقدّم طالبٍ مسجَّلٍ عنده',
        /*
        | Spec 010 · FR-002 · T053 — the five boxes an owner ticks onto an
        | assistant's role, reworded because THIS SCREEN IS THE DELIVERY CHANNEL.
        | 010 grants nothing by default: what an assistant may do is whatever the
        | owner ticks here, so a label that is merely accurate is not enough — a
        | box that is misread is granted, and FR-002 fails in BEHAVIOUR while
        | being perfectly implemented in code.
        |
        | The composed forms these replace were «تنفيذ — التصحيح» (a verb with no
        | object: it does not say whose papers), «الردّ — محادثات الطلاب» (which
        | reads as though students chat to each other, a thing FR-016 forbids
        | outright), «إدارة — الدروس» (which does not say it includes uploading
        | the video) and «عرض الرصيد — الأرصدة والفوترة» — the last being the one
        | that matters most, because it is the SINGLE financial-looking permission
        | an owner is allowed to delegate (ت-١) and its label has to say, on the
        | box, that no money travels with it.
        */
        'grading.perform' => 'تصحيح أوراق الطلاب ووضع الدرجات',
        'chat.reply' => 'الردّ على رسائل الطلاب الخاصّة',
        'chat.moderate' => 'حذف رسالةٍ وحظر مشارِكٍ في الشات',
        // ⚠️ «ونشره» في الاسمِ عمداً: النشرُ هو ما يُرسِل الرسالةَ إلى وليِّ الأمر،
        // فمربّعٌ مكتوبٌ عليه «كتابة التقييم» وحدَها يُخفي أنّ التفويضَ يشمل
        // مخاطبةَ الأهل.
        'reviews.periodic.manage' => 'كتابة التقييم الدوريّ للطالب ونشره لوليّ أمره',
        // ⚠️ «إلى كلّ طلابك» على المربّعِ عمداً، للسببِ نفسِه. المُركَّبُ سيقرأ
        // «إدارة — الإعلانات»، وهي جملةٌ صحيحةٌ لا تقول إنّ التفويضَ يُرسِل
        // رسالةً باسمِ المدرّسِ إلى ثلاثِمئةِ أسرةٍ لا يستطيع أحدٌ الردَّ عليها.
        'announcements.manage' => 'نشر إعلان إلى كلّ طلابك باسمك',
        'lessons.manage' => 'إضافة الدروس ورفع محتواها',
        'billing.balance.view' => 'عرض أرصدة الطلاب بالحصص — بلا أيّ مبالغ',
    ];

    public static function for(string $permission): string
    {
        if (isset(self::SPECIAL[$permission])) {
            return self::SPECIAL[$permission];
        }

        [$subject, $action] = array_pad(explode('.', $permission, 2), 2, '');

        $noun = self::SUBJECTS[$subject] ?? null;
        $verb = self::ACTIONS[$action] ?? null;

        if ($noun === null || $verb === null) {
            // The honest fallback. A blank label is a tick box nobody can
            // identify; the raw name at least says what it grants.
            return $permission;
        }

        return $verb.' — '.$noun;
    }

    /**
     * Every permission a WORKSPACE role may hold, labelled — the role screen's
     * whole vocabulary.
     *
     * ⚠️ THE PLATFORM'S PERMISSIONS ARE ABSENT, and their absence is the design.
     * A screen that could grant `billing.pricing.manage` to a workspace role is a
     * screen where an owner makes themselves the platform; the model refuses the
     * write in any case ({@see Role}), and offering a
     * box that always errors is worse than not offering it. Platform standing is
     * granted by NAMING A PERSON, not by ticking a permission — `platform_staff`,
     * and the set behind each platform role comes from code.
     *
     * @return array<string, string>
     */
    public static function tenantMap(): array
    {
        $labels = [];

        foreach (RolePermissionMatrix::tenantPermissions() as $permission) {
            $labels[$permission] = self::for($permission);
        }

        return $labels;
    }
}
