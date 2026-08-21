<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Marketplace's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class MarketplacePersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'marketplace';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['review'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        $userId = $subject->user->getKey();

        /*
        | ⚠️ COMPOSED, NOT COPIED. Each row is built and then intersected with the
        | module's own `PublicFieldAllowlist`, so a column added to `reviews` or to
        | `teacher_profiles` next year cannot reach an archive without somebody
        | putting its name in the list this module already maintains. A second list
        | written inside `Compliance` would be the answer that stops being updated —
        | which is why {@see \App\Modules\Compliance\Support\ExportFieldAllowlist}
        | holds only what no module owns.
        */
        yield from ExportWalk::keyed(
            'review',
            Review::query()
                ->withoutWorkspaceScope()
                ->leftJoin('teacher_profiles', 'teacher_profiles.id', '=', 'reviews.teacher_profile_id')
                ->leftJoin('users', 'users.id', '=', 'teacher_profiles.user_id')
                ->where('reviews.student_id', $userId)
                ->select([
                    'reviews.*',
                    'teacher_profiles.slug as teacher_slug',
                    'users.first_name as teacher_first_name',
                    'users.last_name as teacher_last_name',
                ]),
            fn (Review $review): array => self::only([
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => ExportWalk::at($review->created_at),
                'teacher_slug' => $review->getAttribute('teacher_slug'),
                'teacher_name' => trim(
                    (string) $review->getAttribute('teacher_first_name').' '
                    .(string) $review->getAttribute('teacher_last_name')
                ),
            ], PublicFieldAllowlist::REVIEW),
            column: 'reviews.id',
        );

        /*
        | The subject's OWN teacher profile, when they have one. It is the largest
        | block of free text any person writes about themselves in this product,
        | and `TeacherApplication` is not exported beside it: an application carries
        | the reviewer's notes about the applicant, which is another person's
        | assessment rather than the applicant's own record.
        */
        yield from ExportWalk::keyed(
            'review',
            TeacherProfile::query()->withoutWorkspaceScope()->where('user_id', $userId),
            fn (TeacherProfile $teacher): array => self::only([
                'uuid' => $teacher->uuid,
                'slug' => $teacher->slug,
                'headline' => $teacher->headline,
                'bio' => $teacher->bio,
                'qualifications' => $teacher->qualifications,
                'years_experience' => $teacher->years_experience,
                'teaching_languages' => $teacher->teaching_languages,
                'average_rating' => $teacher->average_rating,
                'reviews_count' => $teacher->reviews_count,
                'is_verified' => $teacher->is_verified,
            ], PublicFieldAllowlist::TEACHER_DETAIL),
        );

        // What this person reported about a teacher, in their own words.
        yield from ExportWalk::keyed(
            'review',
            Complaint::query()->withoutWorkspaceScope()->where('reported_by', $userId),
            fn (Complaint $complaint): array => [
                'uuid' => $complaint->uuid,
                'reason' => $complaint->reason,
                'status' => $complaint->status,
                'created_at' => ExportWalk::at($complaint->created_at),
            ],
        );
    }

    /**
     * The row, reduced to the keys the module's own allowlist names.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function only(array $row, array $allowed): array
    {
        return array_intersect_key($row, array_flip($allowed));
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
