<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\InviteFromWaitlist;
use App\Modules\Learning\Actions\JoinWaitlist;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Actions\CreateOrder;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Carbon;

/*
| ٠٣٤ · US4 — الكورسُ المكتمِلُ لا يُباع، ويُفتَحُ له دَور (FR-023 … FR-029).
|
| ⚠️ **والطالبُ يُبنى بلا `last_workspace_id`** — لا `addWorkspaceMember` ولا
| `setCurrentWorkspace` عليه. طالبُ الإنتاجِ عضوٌ في لا مساحة، فسياقُه `null`
| و`WorkspaceScope` خاملٌ على كلِّ مسارٍ يصلُه؛ وتركيبةٌ تمنحُه سياقاً تقيسُ
| إنساناً لا وجودَ له (قاعدةُ `belongsToCurrentWorkspace` المكتوبةُ في CLAUDE.md).
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'price_minor' => 10_000,
    ]);

    // كورسٌ مكتمل: له مجموعةٌ واحدةٌ ولا مقعدَ فيها.
    $this->cohort = Cohort::factory()->full()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    app(WorkspaceContext::class)->forget();
});

function waitlistStudent(): User
{
    return User::factory()->create();
}

it('keeps the order of registration, including two in the same second', function (): void {
    /*
    | ⚠️ **الثانيةُ نفسُها هي الحالةُ التي تسقطُ على ترتيبٍ بعمودٍ واحد.**
    | `created_at` يتساوى، فبلا المعرِّفِ الثاني يتبدّلُ ترتيبُ الاثنَينِ بينَ
    | فتحةٍ وأخرى للصفحةِ نفسِها — والدَّورُ الذي يتبدّلُ ليسَ دَوراً.
    */
    Carbon::setTestNow('2026-09-14 10:00:00');

    $first = waitlistStudent();
    $second = waitlistStudent();

    app(JoinWaitlist::class)->handle($first, $this->course);
    app(JoinWaitlist::class)->handle($second, $this->course);

    Carbon::setTestNow('2026-09-14 10:00:01');
    $third = waitlistStudent();
    app(JoinWaitlist::class)->handle($third, $this->course);

    Carbon::setTestNow();

    $order = CourseWaitlistEntry::query()
        ->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('student_user_id')
        ->all();

    expect($order)->toBe([$first->getKey(), $second->getKey(), $third->getKey()]);
});

it('takes a student out of the queue the moment they are enrolled, from any door', function (): void {
    $student = waitlistStudent();
    app(JoinWaitlist::class)->handle($student, $this->course);

    app(EnrollStudent::class)->handle($this->course, $student);

    $entry = CourseWaitlistEntry::query()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())->firstOrFail();

    expect($entry->closed_at)->not->toBeNull()
        // ⚠️ `closed_slot` يصيرُ معرِّفَ الصفِّ — فريداً بالتعريف — فيخرجُ الصفُّ
        // من مدى الفهرسِ الفريدِ ويستطيعُ الطالبُ أن يصطفَّ ثانيةً يوماً ما.
        ->and((int) $entry->closed_slot)->toBe((int) $entry->getKey());
});

it('never invites the same person twice under two concurrent claims', function (): void {
    /*
    | ⚠️ **الحالةُ التي تسقطُ على «اقرأِ الأوائلَ N ثمّ اكتبْهم».** موظَّفانِ
    | يفتحانِ مقعدَينِ في اللحظةِ نفسِها يقرآنِ القائمةَ نفسَها: بقراءةٍ ثمّ كتابةٍ
    | يُدعى الأوّلُ مرّتَينِ ويبقى الثاني في الدَّورِ لا يُدعى أبداً.
    |
    | والمطالبةُ هنا تُقاسُ بلا خيوطٍ ولا انتظار: تُستدعى الدعوةُ مرّتَين، والصفُّ
    | المطالَبُ في الأولى لا يُطالَبُ في الثانية.
    */
    $first = waitlistStudent();
    $second = waitlistStudent();

    app(JoinWaitlist::class)->handle($first, $this->course);
    app(JoinWaitlist::class)->handle($second, $this->course);

    // مقعدٌ واحدٌ فُتِح.
    $this->cohort->forceFill(['capacity' => 2, 'members_count' => 1])->save();

    $officer = $this->owner;

    expect(app(InviteFromWaitlist::class)->handle($this->cohort->refresh(), $officer))->toBe(1)
        // والجولةُ الثانيةُ على المقعدِ نفسِه تدعو **الثاني**، لا الأوّلَ ثانيةً.
        ->and(app(InviteFromWaitlist::class)->handle($this->cohort->refresh(), $officer))->toBe(1);

    $invited = CourseWaitlistEntry::query()->withoutWorkspaceScope()
        ->whereNotNull('invited_at')->pluck('student_user_id')->all();

    sort($invited);
    $expected = [$first->getKey(), $second->getKey()];
    sort($expected);

    expect($invited)->toBe($expected);

    // ومَن دُعيَ يُبلَّغ، ومرّةً واحدة.
    expect(Notification::query()
        ->where('recipient_user_id', $first->getKey())
        ->where('type', 'waitlist_invited')
        ->count())->toBe(1);
});

it('still sells a course that has no groups at all', function (): void {
    /*
    | ⛔ **الحالةُ التي تسقطُ إن كُتِبَ حارسُ FR-023 بشرطٍ واحد.**
    | `assignableCohortsExist()` يردُّ `false` على الكورسِ المكتمِلِ **وعلى الكورسِ
    | بلا مجموعاتٍ إطلاقاً** بالقيمةِ نفسِها — فحارسٌ من سطرٍ واحدٍ يمنعُ بيعَ
    | كلِّ محتوًى مسجَّلٍ على المنصّة، وهي FR-025 مقلوبةً على مسارِ المال.
    */
    $recorded = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'price_minor' => 10_000,
    ]);

    $buyer = waitlistStudent();

    $order = app(CreateOrder::class)->handle($recorded, $buyer);

    expect($order->exists)->toBeTrue();

    // وبابُ المالِ على الكورسِ المكتمِلِ مغلقٌ في الوقتِ نفسِه.
    expect(fn () => app(CreateOrder::class)->handle($this->course, waitlistStudent()))
        ->toThrow(DomainException::class);
});

it('refuses a FREE full course too, which never passes through an order', function (): void {
    /*
    | ⛔ **الحالةُ التي تسقطُ إن نُسِيَ البابُ الثاني.** الكورسُ المجّانيُّ لا يمرُّ
    | من إنشاءِ الطلبِ إطلاقاً، فحارسٌ في `CreateOrder` وحدَه يترُكُ كورساً مجّانيّاً
    | مكتمِلاً يقبلُ عدداً بلا حدّ — غرفةٌ بثلاثينَ كرسيّاً وبابٌ بلا سقف.
    */
    $free = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'price_minor' => 0,
        'status' => 'published',
    ]);

    Cohort::factory()->full()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $free->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $student = waitlistStudent();

    $this->actingAs($student)
        ->postJson('/api/v1/courses/'.$free->uuid.'/enroll')
        ->assertStatus(422)
        ->assertJsonPath('code', 'course_full');
});

it('refuses a waitlist entry on a course that still has a place', function (): void {
    // صفٌّ في دَورِ كورسٍ فيه مقعدٌ شاغرٌ يُخفي عن الطالبِ أنّه يستطيعُ الدخولَ الآن.
    $open = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $open->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    expect(fn () => app(JoinWaitlist::class)->handle(waitlistStudent(), $open))
        ->toThrow(DomainException::class);
});

it('answers one refusal for a uuid that is not theirs and for one that does not exist', function (): void {
    /*
    | ⚠️ **جوابانِ مختلفانِ هنا عرّافٌ**: يقولُ للسائلِ أيُّ المعرِّفاتِ يخصُّ
    | إنساناً حقيقيّاً — وهو بابُ استعلامٍ عن هويّةِ كلِّ طالبٍ على المنصّة.
    */
    $actor = waitlistStudent();
    $stranger = waitlistStudent();

    $refusals = [];

    foreach ([(string) $stranger->uuid, (string) Str::uuid()] as $uuid) {
        try {
            app(JoinWaitlist::class)->handle($actor, $this->course, $uuid);
        } catch (DomainException $e) {
            $refusals[] = $e->getMessage();
        }
    }

    expect($refusals)->toHaveCount(2)
        ->and($refusals[0])->toBe($refusals[1]);
});

it('skips a row another runner claimed inside the read-to-claim window', function (): void {
    /*
    | ⛔ **الحالةُ الوحيدةُ التي تقيسُ المطالبةَ الذرّيّة، والاختبارُ المتتابعُ لا
    | يراها إطلاقاً.** «ادعُ مرّتَين» يمرُّ على بناءٍ **لا مطالبةَ فيه**: قائمةُ
    | المرشَّحينَ تستثني المدعوّينَ أصلاً، فالصفُّ الواحدُ لا يُقرَأُ مرّتَين.
    | والنافذةُ الحقيقيّةُ بينَ قراءةِ القائمةِ وكتابةِ الصفّ — موظَّفانِ يقرآنِ
    | القائمةَ نفسَها معاً.
    |
    | وتُفتَحُ تلكَ النافذةُ هنا بلا خيوطٍ ولا انتظار: `retrieved` يقعُ داخلَ
    | `get()` نفسِها، فكتابةٌ منافِسةٌ فيه **هي** الرفيقُ يفوزُ في عينِ اللحظة —
    | وهي حيلةُ `onCreateRoom` و`onRecording` في هذه الشجرةِ من بابٍ ثالث.
    */
    $student = waitlistStudent();
    app(JoinWaitlist::class)->handle($student, $this->course);

    $this->cohort->forceFill(['capacity' => 2, 'members_count' => 1])->save();

    $stolen = false;

    CourseWaitlistEntry::retrieved(function (CourseWaitlistEntry $entry) use (&$stolen): void {
        if ($stolen) {
            return;
        }

        $stolen = true;

        // الرفيقُ يدعوه أوّلاً، بينما هذه الجولةُ لا تزالُ تقرأُ.
        DB::table('course_waitlist_entries')
            ->where('id', $entry->getKey())
            ->update(['invited_at' => now()->subMinute()]);
    });

    $invited = app(InviteFromWaitlist::class)->handle($this->cohort->refresh(), $this->owner);

    expect($invited)->toBe(0)
        // ولا رسالةَ ثانيةٌ عن دعوةٍ أرسلَها الرفيق.
        ->and(Notification::query()
            ->where('recipient_user_id', $student->getKey())
            ->where('type', 'waitlist_invited')
            ->count())->toBe(0);
});
