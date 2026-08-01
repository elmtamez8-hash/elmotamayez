<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Permission name constants. Seeded into spatie/permission's permissions table.
 * Each permission is a granular action scoped to a resource and operation.
 */
final class Permissions
{
    // Members
    public const MEMBERS_VIEW = 'members.view';

    public const MEMBERS_INVITE = 'members.invite';

    public const MEMBERS_UPDATE = 'members.update';

    public const MEMBERS_REMOVE = 'members.remove';

    // Courses
    public const COURSES_VIEW = 'courses.view';

    public const COURSES_CREATE = 'courses.create';

    public const COURSES_UPDATE = 'courses.update';

    public const COURSES_DELETE = 'courses.delete';

    public const COURSES_PUBLISH = 'courses.publish';

    public const COURSES_ARCHIVE = 'courses.archive';

    // Lessons
    public const LESSONS_MANAGE = 'lessons.manage';

    public const LESSONS_DELETE = 'lessons.delete';

    public const LESSONS_PROGRESS_COMPLETE_OWN = 'lessons.progress.complete.own';

    // Enrollments
    public const ENROLLMENTS_VIEW_ALL = 'enrollments.view.all';

    public const ENROLLMENTS_VIEW_OWN = 'enrollments.view.own';

    public const ENROLLMENTS_CREATE_MANUAL = 'enrollments.create.manual';

    // Exams
    public const EXAMS_VIEW = 'exams.view';

    public const EXAMS_CREATE = 'exams.create';

    public const EXAMS_UPDATE = 'exams.update';

    public const EXAMS_DELETE = 'exams.delete';

    public const EXAMS_PUBLISH = 'exams.publish';

    public const QUESTIONS_MANAGE = 'questions.manage';

    // Attempts
    public const ATTEMPTS_VIEW_ALL = 'attempts.view.all';

    public const ATTEMPTS_VIEW_OWN = 'attempts.view.own';

    public const ATTEMPTS_SUBMIT = 'attempts.submit';

    // Certificates
    public const CERTIFICATES_VIEW_ALL = 'certificates.view.all';

    public const CERTIFICATES_VIEW_OWN = 'certificates.view.own';

    public const CERTIFICATES_REGENERATE = 'certificates.regenerate';

    // Orders / Payments
    public const ORDERS_VIEW_ALL = 'orders.view.all';

    public const ORDERS_VIEW_OWN = 'orders.view.own';

    public const ORDERS_CREATE = 'orders.create';

    public const PAYMENTS_APPROVE = 'payments.approve';

    public const PAYMENTS_REJECT = 'payments.reject';

    // CMS
    public const CMS_VIEW = 'cms.view';

    public const CMS_CREATE = 'cms.create';

    public const CMS_UPDATE = 'cms.update';

    public const CMS_DELETE = 'cms.delete';

    public const CMS_PUBLISH = 'cms.publish';

    // Analytics
    public const ANALYTICS_VIEW = 'analytics.view';

    // Settings
    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_UPDATE = 'settings.update';

    // Marketplace
    public const MARKETPLACE_TEACHERS_REVIEW = 'marketplace.teachers.review';

    public const MARKETPLACE_TEACHERS_APPROVE = 'marketplace.teachers.approve';

    public const MARKETPLACE_TEACHERS_SUSPEND = 'marketplace.teachers.suspend';

    public const MARKETPLACE_REVIEWS_MODERATE = 'marketplace.reviews.moderate';

    public const MARKETPLACE_COMPLAINTS_MANAGE = 'marketplace.complaints.manage';

    public const MARKETPLACE_PARTICIPATION_MANAGE = 'marketplace.participation.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MEMBERS_VIEW,
            self::MEMBERS_INVITE,
            self::MEMBERS_UPDATE,
            self::MEMBERS_REMOVE,
            self::COURSES_VIEW,
            self::COURSES_CREATE,
            self::COURSES_UPDATE,
            self::COURSES_DELETE,
            self::COURSES_PUBLISH,
            self::COURSES_ARCHIVE,
            self::LESSONS_MANAGE,
            self::LESSONS_DELETE,
            self::LESSONS_PROGRESS_COMPLETE_OWN,
            self::ENROLLMENTS_VIEW_ALL,
            self::ENROLLMENTS_VIEW_OWN,
            self::ENROLLMENTS_CREATE_MANUAL,
            self::EXAMS_VIEW,
            self::EXAMS_CREATE,
            self::EXAMS_UPDATE,
            self::EXAMS_DELETE,
            self::EXAMS_PUBLISH,
            self::QUESTIONS_MANAGE,
            self::ATTEMPTS_VIEW_ALL,
            self::ATTEMPTS_VIEW_OWN,
            self::ATTEMPTS_SUBMIT,
            self::CERTIFICATES_VIEW_ALL,
            self::CERTIFICATES_VIEW_OWN,
            self::CERTIFICATES_REGENERATE,
            self::ORDERS_VIEW_ALL,
            self::ORDERS_VIEW_OWN,
            self::ORDERS_CREATE,
            self::PAYMENTS_APPROVE,
            self::PAYMENTS_REJECT,
            self::CMS_VIEW,
            self::CMS_CREATE,
            self::CMS_UPDATE,
            self::CMS_DELETE,
            self::CMS_PUBLISH,
            self::ANALYTICS_VIEW,
            self::SETTINGS_VIEW,
            self::SETTINGS_UPDATE,
            self::MARKETPLACE_TEACHERS_REVIEW,
            self::MARKETPLACE_TEACHERS_APPROVE,
            self::MARKETPLACE_TEACHERS_SUSPEND,
            self::MARKETPLACE_REVIEWS_MODERATE,
            self::MARKETPLACE_COMPLAINTS_MANAGE,
            self::MARKETPLACE_PARTICIPATION_MANAGE,
        ];
    }
}
