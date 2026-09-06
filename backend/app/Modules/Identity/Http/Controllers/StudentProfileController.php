<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ما يُصحِّحُه الطالبُ في بياناتِه الدراسيّةِ بعدَ التسجيل.
 *
 * ⚠️ لم يكنْ لهذا بابٌ إطلاقاً: `student_profiles` يُكتَبُ مرّةً واحدةً عندَ
 * التسجيلِ ولا شيءَ بعدَها. وطالبٌ في الصفِّ التاسعِ يصيرُ في العاشرِ كلَّ سنة —
 * فبيانٌ يتغيَّرُ بالضرورةِ ولا بابَ له بيانٌ خاطئٌ بمرورِ الوقت، لا بيانٌ ثابت.
 *
 * ⛔ **وتاريخُ الميلادِ ورقمُ وليِّ الأمرِ ليسا هنا عمداً.** الأوّلُ يقودُ بوّابةَ
 * موافقةِ وليِّ الأمرِ (FR-009) وكنسةَ بلوغِ الرشدِ في ٠١٣، فتغييرُه من شاشةِ
 * إعداداتٍ تغييرٌ لواقعةٍ قانونيّةٍ بضغطةٍ — والثاني هو العنوانُ الذي تُطلَبُ عليه
 * تلكَ الموافقة، فبابٌ يفتحُه القاصرُ لنفسِه يُبطِلُ البوّابةَ من داخلِها.
 *
 * ⚠️ والمرحلةُ لا تُكتَبُ: هي مشتقّةٌ من السنةِ (`SchoolYear::stageFor`) منذُ ٠٢٢،
 * وقبولُهما معاً جوابانِ لسؤالٍ واحدٍ يفترقانِ عندَ أوّلِ تعديلٍ للخريطة.
 */
class StudentProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $profile = $user->studentProfile;

        // 403 لا 404: الحسابُ قائمٌ وموقَّع، وإنّما لا ملفَّ طالبٍ له.
        abort_if($profile === null, 403, 'لا يوجد ملف طالب لهذا الحساب.');

        /*
        | ⚠️ نفسُ محمولَي التسجيل: `activelyOffered()` للسنةِ و`is_active`
        | للمنطقة. لو صدَّقَ هذا البابُ قائمةً وصدَّقتِ الشاشةُ أخرى لعادَ عطبُ
        | «جوابانِ لسؤالٍ واحدٍ من جهتَينِ متقابلتَين» الذي دفعتْه ٠٢٢ مرّةً.
        */
        $validated = $request->validate([
            'school_year_slug' => ['required', 'string', Rule::in(
                SchoolYear::query()->activelyOffered()->pluck('slug')->all(),
            )],
            'region_slug' => ['required', 'string', Rule::in(
                Region::query()->where('is_active', true)->pluck('slug')->all(),
            )],
        ], [
            'school_year_slug.in' => 'اختر الصف الدراسي من القائمة.',
            'region_slug.in' => 'اختر المنطقة من القائمة.',
            'required' => 'هذا الحقل مطلوب.',
        ]);

        $profile->forceFill([
            'school_year_slug' => $validated['school_year_slug'],
            // الـFK هو `region_id`، والحمولةُ تحملُ السلَغ — نفسُ الفصلِ الذي
            // يقيمُه `RegisterStudent`، والحلُّ في الإجراءِ لا في الحمولة.
            'region_id' => Region::query()->where('slug', $validated['region_slug'])->value('id'),
        ])->save();

        return response()->json(UserResource::make($user->fresh()));
    }
}
