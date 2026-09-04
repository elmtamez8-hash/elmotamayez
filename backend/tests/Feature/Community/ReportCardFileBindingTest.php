<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\ReportCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE LINK NAMED A READER AND NOTHING COMPARED IT.
|
| `ReportCardController::download()` mints a five-minute signed URL and its
| comment says the parameter «ties the link to the person it was minted for — a
| forwarded URL is then a signature over somebody else's id». But `file()` read
| the card uuid alone, and the route carried no `auth:sanctum`, so there was no
| authenticated reader to compare against either. A parameter inside a signature
| binds nobody unless something reads it.
|
| What that cost: whoever obtained the URL inside five minutes — a shared
| browser's history, a referrer, a parent forwarding «شوفي» into a family group —
| downloaded a named minor's full grade PDF with no account at all.
|
| ⚠️ THE FIX IS THE SHAPE THE MODULE NEXT DOOR ALREADY HAD.
| `SubmissionFileController` sits behind `auth:sanctum` AND `signed`, re-runs its
| policy at open, and compares `reader` to the caller. One spelling, two modules.
|
| ⚠️ AND THE SIGNATURE STAYS. It is not the identity check — it is the five-minute
| bound, which is what makes a forwarded link EXPIRE rather than merely belong to
| the wrong person.
*/

/** The link exactly as `download()` mints it. */
function reportCardFileUrl(ReportCard $card, User $reader): string
{
    return URL::temporarySignedRoute(
        'report-cards.file',
        now()->addMinutes(5),
        ['uuid' => $card->uuid, 'reader' => (string) $reader->uuid],
    );
}

function publishedCardFor(User $student): ReportCard
{
    return ReportCard::factory()->published()->create(['student_user_id' => $student->getKey()]);
}

it('refuses a perfectly valid signature presented by nobody', function (): void {
    $student = User::factory()->create();

    $this->getJson(reportCardFileUrl(publishedCardFor($student), $student))->assertUnauthorized();
});

it('refuses a link that travelled to another account', function (): void {
    $student = User::factory()->create();
    $url = reportCardFileUrl(publishedCardFor($student), $student);

    // A stranger who obtained the URL and happens to hold an account of their own.
    Sanctum::actingAs(User::factory()->create());
    $this->asGuest();

    /*
    | 403 rather than 404 here: this one is refused by the POLICY, before the
    | reader comparison is reached — the card is not theirs to read at all. The
    | case below is the one the comparison itself exists for.
    */
    $this->getJson($url)->assertForbidden();
});

it('refuses a link minted for one reader and opened by another who may read the card', function (): void {
    $student = User::factory()->create();
    $card = publishedCardFor($student);

    /*
    | ⚠️ THE FILE IS ATTACHED HERE TOO, AND THE FIRST DRAFT OF THIS TEST WAS GREEN
    | WITHOUT IT — FOR THE WRONG REASON. With no PDF on the card, `file()` answers
    | 404 from its own `abort_if($media === null)`, which is the SAME status the
    | binding returns; the case passed against a build with the binding deleted.
    | Caught by deleting it and re-running, which is the only thing that could
    | have caught it.
    */
    $card->addMedia(UploadedFile::fake()->create('card.pdf', 8, 'application/pdf'))
        ->toMediaCollection('report_card_pdf');

    // The signature names somebody else; the caller is the card's own subject,
    // so the policy says yes and only the binding can refuse.
    $url = reportCardFileUrl($card, User::factory()->create());

    Sanctum::actingAs($student);
    $this->asGuest();

    // 404, not 403: "wrong person" is itself information.
    $this->getJson($url)->assertNotFound();
});

it('lets the reader the link was minted for through to the file check', function (): void {
    $student = User::factory()->create();
    $card = publishedCardFor($student);

    $card->addMedia(UploadedFile::fake()->create('card.pdf', 8, 'application/pdf'))
        ->toMediaCollection('report_card_pdf');

    Sanctum::actingAs($student);
    $this->asGuest();

    /*
    | ⚠️ THE POSITIVE CONTROL, AND IT ATTACHES A REAL FILE SO THE ANSWER IS 200.
    | Written against a card with no PDF it would assert 404 — the SAME status the
    | binding returns when it refuses — and a control that cannot be told apart
    | from the failure it exists to rule out is not a control. A guard refusing
    | everybody passes all three negations above; only this line fails on it.
    */
    $this->getJson(reportCardFileUrl($card, $student))->assertOk();
})->group('positive-control');
