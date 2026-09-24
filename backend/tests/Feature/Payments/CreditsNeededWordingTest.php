<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Media\Exceptions\AccessWithheldException;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Support\CountedNoun;

/*
| «تحتاج 1 حصة على الأقل» — measured on production 2026-09-24, on the booking
| refusal and on the high-value file refusal, which quote the same number.
| A Latin digit before a noun that agreed with it in no band: one is «حصة
| واحدة» with no numeral, two is the accusative dual «حصتين» (the sentence
| wants an object), three to ten take the plural, eleven goes back to the
| singular. Measured at all four, on both refusals, because the two sentences
| are written in two modules and a fix to one has never reached the other.
*/

/** @return array<int, array{int, string}> */
function creditsNeededWordingBands(): array
{
    return [
        [1, 'تحتاج حصة واحدة على الأقل'],
        [2, 'تحتاج حصتين على الأقل'],
        [3, 'تحتاج ٣ حصص على الأقل'],
        [11, 'تحتاج ١١ حصة على الأقل'],
    ];
}

function creditsNeededWordingStanding(int $needed): void
{
    $standing = mock(AccountStanding::class);
    $standing->shouldReceive('refusalFor')->andReturn(['withheld' => true, 'credits_needed' => $needed]);
    app()->instance(AccountStanding::class, $standing);
}

it('words the booking refusal in the band of the number', function (int $needed, string $phrase): void {
    creditsNeededWordingStanding($needed);

    $session = ClassSession::factory()->make(['course_id' => 1, 'workspace_id' => 1, 'teacher_profile_id' => 1]);
    $eligibility = app(BookingEligibility::class);

    $sentence = Closure::bind(
        fn (ClassSession $s, User $u): ?string => $this->withholdingRefusal($s, $u),
        $eligibility,
        BookingEligibility::class,
    )($session, User::factory()->make());

    expect($sentence)->toContain($phrase)
        ->and($sentence)->not->toMatch('/[0-9]/');
})->with(creditsNeededWordingBands());

it('words the file refusal in the band of the number', function (int $needed, string $phrase): void {
    creditsNeededWordingStanding($needed);

    $lesson = Lesson::factory()->make(['is_high_value' => true, 'course_id' => 1]);
    $grant = app(IssuePlaybackGrant::class);

    $refuse = Closure::bind(
        fn (Lesson $l, User $u) => $this->assertNotWithheld($l, $u),
        $grant,
        IssuePlaybackGrant::class,
    );

    try {
        $refuse($lesson, User::factory()->make());
        $this->fail('A withheld high-value file must be refused.');
    } catch (AccessWithheldException $refusal) {
        expect($refusal->getMessage())->toContain($phrase)
            ->and($refusal->getMessage())->not->toMatch('/[0-9]/')
            ->and($refusal->creditsNeeded)->toBe($needed);
    }
})->with(creditsNeededWordingBands());

it('keeps the shared forms in the accusative', function (): void {
    expect(array_map(
        fn (int $n): string => CountedNoun::of($n, CountedNoun::SESSIONS_OBJECT),
        [1, 2, 3, 11, 100],
    ))->toBe(['حصة واحدة', 'حصتين', '٣ حصص', '١١ حصة', '١٠٠ حصة']);
});
