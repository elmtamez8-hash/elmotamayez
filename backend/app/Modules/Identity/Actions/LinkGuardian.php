<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Links a guardian to a student. Replaces AddChild.
 *
 * Two rules live here rather than in validation, because the Action is the shared
 * entrance for the API, seeders and Filament (Constitution II):
 *
 *  - at most one active parent per student (FR-019). It is not a unique index:
 *    the constraint is "one active row", and a partial unique index is written
 *    differently on SQLite and MySQL, so the two environments would disagree
 *    about what the schema enforces.
 *  - a student account can only be attached once by the same guardian.
 */
class LinkGuardian extends Action
{
    public function __construct(private readonly DispatchNotification $notifications) {}

    public function handle(User $guardian, LinkGuardianData $data): ParentStudentRelation
    {
        $student = $data->studentUuid === null ? null : $this->resolveStudent($guardian, $data->studentUuid);

        if ($student !== null && $this->alreadyLinked($guardian, $student)) {
            throw new DomainException('هذا الطالب مرتبط بحسابك بالفعل.');
        }

        if ($data->relationType === RelationType::Parent && $student !== null && $this->hasActiveParent($student)) {
            throw new DomainException('لهذا الطالب وليّ أمر مسجَّل. يمكن إضافة وصيّ بدلاً من ذلك.');
        }

        $relation = ParentStudentRelation::query()->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $student?->getKey(),
            'student_name' => $data->studentName,
            'student_age' => $data->studentAge,
            // The new column; `student_grade_level_slug` stays NULL and is the
            // fallback for relations created before years existed. The stage is
            // derived (`ParentStudentRelation::stageSlug`), never stored twice.
            'student_school_year_slug' => $data->schoolYearSlug,
            'relation_type' => $data->relationType->value,
            'permissions' => array_map(fn ($permission) => $permission->value, $data->permissions),
            // A guardian added for a child with no account yet is active at once:
            // there is nobody who could accept it. Once the student has an account
            // of their own, the link starts pending until they do.
            'status' => $student === null ? RelationStatus::Active->value : RelationStatus::Pending->value,
            /*
            | WHO ASKED (spec 030). The decider is the party who did NOT — the
            | student here, and the guardian in `RegisterStudent`'s mirror of this
            | write. Without this column one rule cannot serve both directions.
            */
            'requested_by_user_id' => $guardian->getKey(),
        ]);

        if ($student !== null) {
            $this->notifyStudent($guardian, $student, $relation);
        }

        return $relation;
    }

    /**
     * The named student learns a link is waiting on them (FR-006).
     *
     * Only when there is an account to tell. A name-only child has nobody to
     * notify, which is the same fact that makes their row `active` at once.
     *
     * Dispatched inline rather than through an event: Identity calls
     * `DispatchNotification` directly in five places and fires no event that
     * Notifications listens to — and `RegisterStudent` notifies about THIS SAME
     * ROW from the other direction the same way.
     */
    private function notifyStudent(User $guardian, User $student, ParentStudentRelation $relation): void
    {
        $this->notifications->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::GuardianLinkRequested,
            variables: [
                'name' => $guardian->name,
                'relation_type' => $relation->relationType()->label(),
            ],
            // Without this the feed row renders as a plain <div>: `NotificationRow`
            // wraps a link only when `action_url` is set, so the one notification
            // that exists to be acted on would lead nowhere.
            actionUrl: '/family',
            subject: $student,
        ));
    }

    /**
     * Attaching an existing account is the one place a guardian names someone
     * else's record, so the rules are strict: it must be a student account, and
     * "not found" and "not a student" answer the same way — otherwise the endpoint
     * confirms which accounts exist on the platform.
     */
    private function resolveStudent(User $guardian, string $uuid): User
    {
        /*
        | ⛔ ATTACHING AN EXISTING ACCOUNT IS A GUARDIAN'S ACT, AND NOTHING SAID SO
        | UNTIL SPEC 030.
        |
        | `LinkGuardianRequest::authorize()` returns true, `FamilyController::store`
        | asks no policy, and this method used to check only the TARGET — so, in
        | `DataRequestPolicy`'s own words, "a pending link … any user can create for
        | any student uuid". Those uuids are not secret: `ReadSessionRoster` hands
        | one to every seat holder in a live room and `AttendanceResource` to every
        | teacher.
        |
        | That was inert while `pending` granted nothing. THE ACCEPT ROUTE IS WHAT
        | ARMS IT: a teacher or a classmate requests a link carrying `data_rights`,
        | the child taps accept once, and the requester can open a data-rights
        | request that assembles everything the platform knows about them — with the
        | constitutional teacher-visibility guard bypassed entirely, because they
        | are no longer reading as a teacher.
        |
        | ⚠️ THE SAME UNDIFFERENTIATED SENTENCE as the refusal below, or the endpoint
        | becomes an oracle for platform roles instead of one for accounts. It also
        | closes a self-link, since a Parent account is never a Student one.
        */
        if ($guardian->platform_role !== PlatformRole::Parent) {
            throw new DomainException('لم نجد حساب طالب بهذا المعرّف.');
        }

        $student = User::query()
            ->where('uuid', $uuid)
            ->where('platform_role', PlatformRole::Student)
            ->first();

        if ($student === null) {
            throw new DomainException('لم نجد حساب طالب بهذا المعرّف.');
        }

        return $student;
    }

    /**
     * ⚠️ LIVE ROWS ONLY (spec 030 · FR-012). A revoked link may be requested
     * again — otherwise a refusal is permanent and the "re-request" the rate limit
     * exists to bound could never happen.
     *
     * This is the friendly message, not the guard. The guard is
     * `unique(guardian_user_id, student_user_id, live_slot)`: an `exists()` here
     * followed by a `create()` below is a read-then-write, and two active rows for
     * one pair would make `EloquentGuardianDirectory::isAuthorised()` — a bare
     * `first()` — non-deterministic AND leave `RevokeRelation` cutting one row
     * while the other keeps granting access.
     */
    private function alreadyLinked(User $guardian, User $student): bool
    {
        return ParentStudentRelation::query()
            ->where('guardian_user_id', $guardian->getKey())
            ->where('student_user_id', $student->getKey())
            ->whereIn('status', [RelationStatus::Pending->value, RelationStatus::Active->value])
            ->exists();
    }

    private function hasActiveParent(User $student): bool
    {
        return ParentStudentRelation::query()
            ->forStudent($student)
            ->where('relation_type', RelationType::Parent->value)
            ->whereIn('status', [RelationStatus::Active->value, RelationStatus::Pending->value])
            ->exists();
    }
}
