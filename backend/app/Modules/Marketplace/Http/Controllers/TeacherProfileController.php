<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\SetAvailability;
use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Actions\UpdateTeacherSlug;
use App\Modules\Marketplace\Http\Requests\SetAvailabilityRequest;
use App\Modules\Marketplace\Http\Requests\UpdateTeacherProfileRequest;
use App\Modules\Marketplace\Http\Requests\UpdateTeacherSlugRequest;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's own listing, edited by the teacher.
 *
 * Separate from TeacherApplicationController, which owns the four-step wizard:
 * these are edits to a profile that already exists, and a method that means
 * "change my public URL" sitting among the application steps reads as a fifth
 * step nobody has to complete.
 */
class TeacherProfileController extends Controller
{
    /**
     * What the settings card needs to render itself.
     *
     * `slug` is null for an account with no listing yet — an application still
     * in review — and the card says so rather than offering a field that saves
     * into nothing. Deliberately NOT part of `/auth/me`: a slug is true of a
     * teacher and of nobody else, and `users` payloads carry what every account
     * has.
     *
     * ⚠️ AND «NO ROW» IS A REFUSAL, NOT A NULL SLUG. The two are one enum apart
     * on the wire and worlds apart on the screen: a pending teacher HAS a row
     * (`SubmitTeacherApplication` creates it, slug null, pending) and is told
     * «لم يُنشر ملفك بعد»; a student has no row at all, and answering them with
     * the same body printed «رابط ملفك العام» on a student's settings page,
     * under a heading about the address parents reach *their* page at.
     *
     * `PublicProfileUrlCard` was written against this refusal and says so in as
     * many words — «a student opening /settings gets a 403 here» — and the
     * `.catch` it swallows the 403 with never ran, because the 403 did not
     * exist. A documented guard that is not implemented is worse than an absent
     * one: it ends the review that would have found it. Same spelling as
     * `updateSlug()` one method below, which had it from the start.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->currentUser($request)->teacherProfile;

        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        return response()->json([
            'slug' => $profile->slug,
            'is_publicly_listed' => (bool) $profile->is_publicly_listed,
            /*
            | Spec 011 · T117 — the workspace's own flag, BESIDE the derived one
            | rather than instead of it.
            |
            | ⚠️ THE TWO ANSWER DIFFERENT QUESTIONS AND THE BLOG RIDES ON THIS
            | ONE. `is_publicly_listed` is derived from approval AND
            | participation, so a teacher still in review reads `false` while
            | their workspace is perfectly well opted in — and `Article`'s public
            | predicate asks only about the workspace. So an unapproved teacher's
            | ARTICLES are public while their profile is not, and the settings
            | card cannot say that from the derived flag alone.
            |
            | It is the teacher's own workspace, so nothing crosses a tenant
            | boundary; `participates_in_marketplace` is not a public field and
            | does not appear in any marketplace payload.
            */
            'workspace_participates_in_marketplace' => (bool) $profile->workspace?->participates_in_marketplace,
            /*
            | ⚠️ WHAT THE EDIT FORM PREFILLS ITSELF FROM — and until 2026-09-06
            | there was nowhere at all to edit these. Every one of them was
            | written ONCE, in step two of the application wizard, and after
            | approval no screen and no route could touch them again: a teacher
            | who took on a new stage, earned a qualification, or wanted to fix a
            | typo in their own description had to ask the platform to edit the
            | row for them in `/admin`.
            |
            | The photo is the sharper half of the same gap: `photo_path` had
            | FOUR readers and no writer anywhere in the tree, so the initials
            | every avatar falls back to were not a fallback — they were the only
            | state the product could ever be in.
            */
            'photo_url' => $profile->photo_path === null ? null : asset('storage/'.$profile->photo_path),
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'years_experience' => $profile->years_experience,
            'qualifications' => $profile->qualifications ?? [],
            /*
            | ⚠️ **القراءةُ العامّةُ ليست هذه القراءة، والحقلُ يحتاجُ الاثنتَين.**
            | `PublicTeacherDetailResource` يُجيبُ الزائرَ، وهذا يُجيبُ صاحبَ الملفِّ
            | — ونموذجُ التحريرِ يملأُ نفسَه من هنا وحدَه. فحقلٌ أُضيفَ هناك ونُسِيَ
            | هنا يصلُ العميلَ `undefined`، و`form.faqs.length` ينفجرُ في المتصفِّح
            | بينما كلُّ اختبارٍ أخضرُ — تجهيزةُ الاختبارِ تصفُ ما ظُنَّ لا ما يُرسَل.
            */
            'faqs' => $profile->faqs ?? [],
            'intro_video_url' => $profile->intro_video_url,
            'teaching_languages' => $profile->teaching_languages ?? [],
            'subjects' => $profile->subjects()->pluck('slug')->all(),
            'grade_levels' => $profile->gradeLevels()->pluck('slug')->all(),
            /*
            | ⚠️ معَ الملفِّ في ردٍّ واحد، لا نقطةَ نهايةٍ ثانية — والقراءةُ العامَّةُ
            | (`PublicTeacherDetailResource`) لا تصلحُ بديلاً: هي خلفَ
            | `publiclyListed()`، فمدرّسٌ قيدَ المراجعةِ يقرأُ أسبوعَهُ فارغاً ثمَّ
            | يحفظُ فوقَه.
            |
            | و`H:i:s` كما يخزّنُها العمود؛ العميلُ يقتطعُ الثواني كما يفعلُ المعالجُ
            | سلفاً، ويحوّلُ من UTC بـ`toLocalSlot`.
            */
            'availability' => $profile->availabilitySlots()
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get(['day_of_week', 'start_time', 'end_time'])
                ->all(),
        ]);
    }

    /**
     * The teacher edits their own listing, and it is public at once.
     *
     * ⚠️ THROUGH THE SAME ACTION THE REVIEW TEAM USES. `UpdateTeacherProfile`
     * already existed and was reachable from `/admin` alone: it carries the
     * white-list that keeps `approval_status` and `is_publicly_listed` out of a
     * raw `update()`, and it flushes the marketplace cache — without which a
     * saved change stays invisible on the public card until the cache expires by
     * itself. A second writer here would have to remember both.
     *
     * ⚠️ AND IT DOES NOT RE-OPEN REVIEW. `approval_status` is untouched, so
     * `is_publicly_listed` — derived from approval AND workspace participation —
     * cannot move. Sending an edit back to a queue was considered and refused:
     * it would leave a teacher's own page carrying, for days, a sentence they
     * had already corrected.
     */
    public function update(UpdateTeacherProfileRequest $request, UpdateTeacherProfile $action): JsonResponse
    {
        $profile = $request->profile();

        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        $this->abortIfUnderReview($request);

        $validated = $request->validated();

        $action->handle(
            $profile,
            [
                'headline' => $validated['headline'],
                'bio' => $validated['bio'] ?? null,
                'years_experience' => (int) $validated['years_experience'],
                'qualifications' => array_values($validated['qualifications'] ?? []),
                /*
                | ⚠️ **الشكلُ يُطبَّعُ عندَ الكتابةِ لا عندَ القراءة.** العمودُ JSON،
                | فمفتاحٌ ثالثٌ يرسلُه عميلٌ يُخزَّنُ كما جاءَ ثمّ يُنشَرُ على صفحةٍ
                | عامّة؛ و`PublicFieldAllowlist::FAQ` يعرفُ مفتاحَين اثنَين. فبناءُ
                | الصفِّ هنا بيدِنا هو ما يجعلُ القائمةَ البيضاءَ صادقةً بالبناء.
                */
                'faqs' => array_values(array_map(
                    static fn (array $faq): array => [
                        'question' => $faq['question'],
                        'answer' => $faq['answer'],
                    ],
                    $validated['faqs'] ?? [],
                )),
                'intro_video_url' => $validated['intro_video_url'] ?? null,
                'teaching_languages' => array_values($validated['teaching_languages']),
            ],
            $this->taxonomyIds(Subject::class, $validated['subjects']),
            $this->taxonomyIds(GradeLevel::class, $validated['grade_levels']),
        );

        return $this->show($request);
    }

    /**
     * المواعيدُ الأسبوعيّةُ التي يُحجَزُ فيها هذا المدرّس.
     *
     * ⚠️ الصفوفُ لها أربعةُ قرّاءٍ منذُ ٠٠١ وكاتبٌ واحدٌ يكتبُ مرّةً واحدةً في
     * العمر: {@see SubmitTeacherApplication} عندَ الإرسال. فالمدرّسُ الذي غيّرَ
     * أيّامَه — أو أراد إضافةَ فترةٍ — لم يكنْ أمامَه شيءٌ إطلاقاً، بينما
     * `GenerateSessionsFromAvailability` يبني جدولَه منها،
     * و`RequestPrivateSession` يرفضُ خارجَها، وصفحتُه العامّةُ تعلنُها. عائلةُ
     * `photo_path` نفسُها: عمودٌ له قرّاءٌ وبلا كاتب.
     *
     * ويمرُّ من {@see SetAvailability} نفسِه الذي يمرُّ منه المعالج: هو الذي يعرفُ
     * أنّ الاستبدالَ كاملٌ لا دمج، وأنّ التداخلَ مرفوض، وأنّه يجبُ إفراغُ ذاكرةِ
     * السوقِ وإلّا بقيَ «متاح الآن» يعلنُ نافذةً حذفَها المدرّسُ للتوّ.
     *
     * ⚠️ ولا تحويلَ منطقةٍ زمنيّةٍ هنا: العميلُ يرسلُ UTC (`toUtcSlot`) والعمودُ
     * UTC، فتحويلٌ ثانٍ هنا يُزيحُ كلَّ فترةٍ مرّتَين.
     */
    public function updateAvailability(SetAvailabilityRequest $request, SetAvailability $action): JsonResponse
    {
        $profile = $request->profile();

        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        $this->abortIfUnderReview($request);

        // ولا `try/catch`: رفضُ التداخلِ `DomainException` ويحوّلُه `bootstrap/app.php`
        // إلى ٤٢٢ بجملتِه العربيّةِ نفسِها — والتقاطُه هنا نسخةٌ ثانيةٌ من قاعدةٍ عامّة.
        $action->handle($profile, $request->validated('availability'));

        return $this->show($request);
    }

    /**
     * البابانِ كانا يختلفانِ على سؤالٍ واحد: هل يُعدَّلُ أثناءَ المراجعة؟
     *
     * ⚠️ المعالجُ يقولُ لا — {@see SaveTeacherApplicationStep} يرفضُ كلَّ حفظٍ بعدَ
     * الإرسال — وهاتانِ الشاشتانِ كانتا تقولانِ نعم، بلا حارسٍ إطلاقاً. ومن ذلكَ
     * الخلافِ يُولَدُ الضياع: تعديلٌ يقعُ والطلبُ «مُرسَل» لا تنسخُه المزامنةُ
     * (شرطُها `isEditable()`)، فإن طلبَ المراجِعُ تعديلاً بعدَها كتبَ الإرسالُ
     * التالي الملفَّ من نسخةٍ لا تعرفُه — فالتجميدُ كانَ يؤجّلُ الضياعَ جولةً
     * واحدةً لا يمنعُه.
     *
     * ⚠️ والحارسُ هنا لا في {@see UpdateTeacherProfile}: ذلكَ الفعلُ يخدمُ
     * فاعلَينِ لا واحداً — صاحبَ الطلبِ من هذه الشاشة، وفريقَ المراجعةِ من
     * اللوحة. والمنعُ منعُ صاحبِ الطلبِ من تحريكِ ما يُنظَرُ فيه؛ أمّا المراجِعُ
     * فتصحيحُه هو الغرضُ من شاشتِه. ولذلك هو بابٌ لا فعل.
     *
     * ⚠️ و٤٢٢ لا ٤٠٣: رفضُ حالةٍ لا رفضُ صلاحيّة — الشكلُ نفسُه الذي يردُّ به
     * المعالجُ على الحفظِ بعدَ الإرسال.
     */
    private function abortIfUnderReview(Request $request): void
    {
        $underReview = TeacherApplication::query()
            // `user_id` هو الحارس، والسياقُ قد يكونُ غيرَ سياقِ الطلب.
            ->withoutWorkspaceScope()
            ->where('user_id', $this->currentUser($request)->getKey())
            ->where('status', TeacherApplication::STATUS_SUBMITTED)
            ->exists();

        abort_if($underReview, 422, 'طلبك قيد المراجعة الآن — لا يمكن تعديل بياناتك حتى يردّ الفريق.');
    }

    /**
     * @param  class-string<Subject|GradeLevel>  $model
     * @param  array<int, string>  $slugs
     * @return list<int>
     */
    private function taxonomyIds(string $model, array $slugs): array
    {
        /** @var list<int> */
        return $model::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }

    /**
     * Change the segment the public profile lives at.
     *
     * ⚠️ The profile comes from the authenticated user, never from a route
     * parameter. A `PUT /teachers/{uuid}/slug` would need an ownership check
     * that someone eventually forgets; with no parameter there is nothing to
     * tamper with and no check to forget.
     */
    public function updateSlug(UpdateTeacherSlugRequest $request, UpdateTeacherSlug $action): JsonResponse
    {
        $profile = $request->profile();

        // 403, not 404: the account exists and is signed in, it simply has no
        // listing to rename. A teacher whose application is still in review is
        // the ordinary case here.
        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        $updated = $action->handle($profile, $request->slug());

        return response()->json(['slug' => $updated->slug]);
    }
}
