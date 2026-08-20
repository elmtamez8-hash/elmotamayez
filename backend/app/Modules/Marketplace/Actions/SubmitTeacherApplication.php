<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Data\TeacherStepFourData;
use App\Modules\Marketplace\Data\TeacherStepTwoData;
use App\Modules\Marketplace\Events\TeacherApplicationSubmitted;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the application and build the profile it describes.
 *
 * The profile is created now rather than at approval so the reviewer decides on
 * the real thing. It is created unapproved and unlisted, and nothing here sets
 * is_publicly_listed — that is derived at approval, never assigned.
 */
class SubmitTeacherApplication extends Action
{
    public function __construct(private readonly SetAvailability $availability) {}

    public function handle(TeacherApplication $application): TeacherApplication
    {
        if (! $application->isEditable()) {
            throw new DomainException('تم إرسال الطلب بالفعل.');
        }

        foreach ([2, 3, 4] as $step) {
            if ($application->step($step) === []) {
                throw new DomainException('أكمل كل خطوات الطلب قبل الإرسال.');
            }
        }

        if (($application->step(3)['documents_acknowledged'] ?? false) !== true) {
            throw new DomainException('يجب الإقرار بالمستندات المطلوبة.');
        }

        $two = TeacherStepTwoData::fromArray($application->step(2));
        $four = TeacherStepFourData::fromArray($application->step(4));

        return DB::transaction(function () use ($application, $two, $four): TeacherApplication {
            $profile = TeacherProfile::query()->updateOrCreate(
                ['user_id' => $application->user_id],
                [
                    'workspace_id' => $application->workspace_id,
                    'headline' => $two->headline,
                    'bio' => $two->bio,
                    'qualifications' => $two->qualifications,
                    'years_experience' => $two->yearsExperience,
                    'teaching_languages' => $two->teachingLanguages,
                    'hourly_rate' => $four->hourlyRate,
                    'currency' => $four->currency,
                    // Re-submitting after "changes requested" puts the profile back
                    // in the queue rather than leaving a previously rejected state.
                    'approval_status' => TeacherProfile::STATUS_PENDING,
                    'is_publicly_listed' => false,
                ],
            );

            $profile->subjects()->sync($this->taxonomyIds(Subject::class, $two->subjects));
            $profile->gradeLevels()->sync($this->taxonomyIds(GradeLevel::class, $two->gradeLevels));

            $this->availability->handle($profile, $four->availability);

            $application->forceFill([
                'teacher_profile_id' => $profile->getKey(),
                'status' => TeacherApplication::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'rejection_reason' => null,
            ])->save();

            event(new TeacherApplicationSubmitted($application));

            return $application;
        });
    }

    /**
     * Resolve taxonomy slugs to their rows.
     *
     * Since spec 009 the taxonomy is platform reference data — one row per slug
     * for the whole product — so this no longer resolves "this workspace's math".
     * Unknown slugs are dropped rather than failing the submission: validation
     * already rejected them, and a taxonomy retired between draft and submit
     * should not strand an otherwise complete application.
     *
     * @param  class-string<Subject|GradeLevel>  $model
     * @param  list<string>  $slugs
     * @return array<int, int>
     */
    private function taxonomyIds(string $model, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $model::query()
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->map(intval(...))
            ->values()
            ->all();
    }
}
