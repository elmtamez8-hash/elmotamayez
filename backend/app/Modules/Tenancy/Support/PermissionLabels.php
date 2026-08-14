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
    ];

    /** The handful whose Arabic is not a noun and a verb. @var array<string, string> */
    private const SPECIAL = [
        'lessons.progress.complete.own' => 'إكمال درسٍ لنفسه',
        'attendance.override' => 'تعديل علامة حضور بسببٍ مسجَّل',
        'notifications.templates.manage' => 'إدارة قوالب الرسائل',
        'settlement.audit.view' => 'عرض تدقيق أجور المدرّسين',
        'billing.audit.view' => 'عرض سجلّ التدقيق المالي',
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
