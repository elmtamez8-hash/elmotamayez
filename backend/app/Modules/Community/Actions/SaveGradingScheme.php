<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Data\GradingSchemeData;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Validation\ValidationException;

/**
 * Define how a teacher weights the four grade components (FR-049, FR-050).
 */
class SaveGradingScheme extends Action
{
    public function handle(GradingSchemeData $data): GradingScheme
    {
        /*
        | ⚠️ THE 100% RULE IS ENFORCED HERE, NOT ONLY IN THE FORM REQUEST. The
        | Action is the single entry point the seeders, the panel and the API all
        | share, and a rule that lives only in a request shape is a rule that
        | holds for whichever screen happens to exist today. FR-050 says a scheme
        | that does not reach 100 may not be SAVED — which is a statement about
        | the row, not about one payload.
        */
        $total = array_sum($data->weights);

        if ($total !== 100) {
            throw ValidationException::withMessages([
                'weights' => 'مجموع الأوزان يجب أن يساوي ١٠٠٪ بالضبط.',
            ]);
        }

        foreach ($data->weights as $component => $weight) {
            if (! in_array($component, GradingScheme::COMPONENTS, true)) {
                throw ValidationException::withMessages([
                    'weights' => 'مكوّن غير معروف في تركيبة الأوزان.',
                ]);
            }

            if ($weight < 0) {
                throw ValidationException::withMessages([
                    'weights' => 'لا يمكن أن يكون وزن مكوّن سالباً.',
                ]);
            }
        }

        if ($data->periodEnd->lessThan($data->periodStart)) {
            throw ValidationException::withMessages([
                'period_end' => 'نهاية الفترة قبل بدايتها.',
            ]);
        }

        $workspaceId = (int) app(WorkspaceContext::class)->id();

        $courseId = GradingScheme::ALL_COURSES;

        if ($data->courseUuid !== null) {
            /*
            | ⚠️ RESOLVED AND CHECKED, NEVER CAST. `(int) null === 0`, and 0 is
            | the sentinel meaning "every course in this workspace" — so a uuid
            | that resolves to nothing would silently overwrite the workspace-wide
            | scheme instead of failing. The `unlock_rules.course_id` guard,
            | reached from the same sentinel.
            */
            $course = Course::query()
                ->where('workspace_id', $workspaceId)
                ->where('uuid', $data->courseUuid)
                ->first();

            if ($course === null) {
                throw ValidationException::withMessages([
                    'course_uuid' => 'لا يوجد كورس بهذا المعرّف.',
                ]);
            }

            $courseId = (int) $course->getKey();
        }

        $scheme = GradingScheme::firstOrNew([
            'workspace_id' => $workspaceId,
            'course_id' => $courseId,
            'period_start' => $data->periodStart->toDateString(),
            'period_end' => $data->periodEnd->toDateString(),
        ]);

        $scheme->weights = $data->weights;
        $scheme->save();

        return $scheme;
    }
}
