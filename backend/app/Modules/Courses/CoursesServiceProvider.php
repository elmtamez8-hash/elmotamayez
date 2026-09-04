<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Courses\Listeners\ClaimCoursesForNewTeacherProfile;
use App\Modules\Courses\Listeners\ClearPromoVideoOnOffboarding;
use App\Modules\Courses\Listeners\SyncLessonDurationFromAsset;
use App\Modules\Courses\Support\CoursesPersonalData;
use App\Modules\Marketplace\Events\TeacherApplicationSubmitted;
use App\Modules\Media\Events\MediaAssetReady;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class CoursesServiceProvider extends Module
{
    protected string $name = 'Courses';

    public function boot(): void
    {
        parent::boot();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([CoursesPersonalData::class], 'compliance.personal_data');

        // Media does not know lessons exist. It announces that bytes finished
        // processing; who cares is the subscriber's business.
        Event::listen(MediaAssetReady::class, SyncLessonDurationFromAsset::class);

        /*
        | Spec 018 · FR-012 — a departing teacher's promo videos stop showing.
        |
        | A second subscriber beside `Marketplace\Listeners\UnlistDepartedTeacher`,
        | which hides the departing teacher's courses. This one clears the stored
        | approval so a later relisting cannot bring an unreviewed video back
        | with it — and it is a listener rather than a write from `Compliance`
        | because Constitution III asks for an event across a module boundary.
        */
        Event::listen(TeacherOffboardingCompleted::class, ClearPromoVideoOnOffboarding::class);

        /*
        | ⛔ الكورساتُ التي سبقَت ملفَّ صاحبِها تُطالِبُ به لحظةَ ميلادِه.
        |
        | منذُ ٠٢٥ تُولَدُ مساحةُ المدرّسِ مع التسجيلِ وتحملُ `courses.create` من
        | يومِها، ولا يُخلَقُ `TeacherProfile` إلّا عندَ إرسالِ الطلب. فالتأليفُ
        | قبلَ التقدّمِ هو الترتيبُ الطبيعيّ — وبلا هذا السطرِ يبقى
        | `teacher_profile_id` فارغاً إلى الأبد، فلا يُسعَّرُ الكورسُ أبداً.
        */
        Event::listen(TeacherApplicationSubmitted::class, ClaimCoursesForNewTeacherProfile::class);
    }
}
