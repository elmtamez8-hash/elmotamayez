<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;

/**
 * FR-012 · FR-013 — the recording is announced, and it is entitled by the SEAT.
 *
 * ⚠️ THE ENTITLEMENT RULE ITSELF IS SPEC 017's AND IS ALREADY GUARDED. `accessTo()`
 * refuses to let a recording stand in front of another item, and
 * `IssuePlaybackGrant::mayWatch()` opens it on the booking rather than the course
 * sequence — both directions, both tested where they live. Re-asserting them here
 * would be a second copy of one answer that drifts.
 *
 * ⚠️ WHAT 013 ADDS IS THE DECLARATION, and that is what this file guards: the
 * platform says out loud that a student appears in class recordings, says it as a
 * REQUIRED category, and says it in the room before the recording starts.
 */
it('declares appearing in a recording as a required category with a stated retention', function (): void {
    $category = DataCategory::query()->where('key', 'class_recording')->firstOrFail();

    expect($category->is_required)->toBeTrue()
        // ⚠️ A RETENTION IS DECLARED. "We keep it" with no duration is the answer
        // that makes a right to erasure unmeasurable — and the sweep needs both
        // halves or it does nothing at all.
        ->and($category->retain_days)->not->toBeNull()
        ->and($category->expiry_behaviour)->toBe(ExpiryBehaviour::Delete)
        ->and($category->expires())->toBeTrue();
});

/*
 * ⚠️ THE ASCII NEEDLE, AND IT IS NOT A STYLE CHOICE.
 *
 * `getContent()` escapes non-ASCII, so `expect($response->getContent())
 * ->not->toContain('الرياضيات')` NEVER MATCHES whatever the body holds — the raw
 * body carries `ال...`. Every exposure assertion in this product is about
 * Arabic text, so without re-encoding the whole file would pass against a response
 * that leaked everything.
 */
function catalogueText(): string
{
    $response = test()->getJson('/api/v1/privacy/categories')->assertOk();

    return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

it('publishes the recording category to a visitor who is not signed in', function (): void {
    $this->asGuest();

    $text = catalogueText();

    // The sentence a parent reads, verbatim — the same one the room notice uses.
    expect($text)->toContain('صوتاً وصورةً')
        // And the sentinel proving the assertion above can fail: the escaping trap
        // would make both pass against any payload at all.
        ->and($text)->toContain('class_recording');
});

/*
 * ⚠️ AND THE CATALOGUE CARRIES NO MAP OF THE DATABASE.
 *
 * `table_name` and `column_name` exist so `SC-002` can compare the declaration
 * against the live schema — they are OUR bookkeeping, not the reader's. This route
 * is PUBLIC, so shipping them would hand every visitor a list of personal columns
 * with the table each lives in.
 */
it('never ships the schema hints on the public catalogue', function (): void {
    $this->asGuest();

    $text = catalogueText();

    foreach (['table_name', 'column_name', 'media_assets', 'student_profiles'] as $forbidden) {
        expect($text)->not->toContain($forbidden);
    }
});

/*
 * The announcement is a SURFACE, not a message.
 *
 * ⚠️ AND THE ABSENCE IS ASSERTED, because the obvious implementation is a
 * notification — which arrives after the class, when the person is already in the
 * file. The whole value of announcing is that they could still have chosen not to
 * appear. `RecordingNotice.test.tsx` covers the surface itself; this covers the
 * decision not to build the other thing.
 */
it('adds no notification type for the recording announcement', function (): void {
    $names = array_column(NotificationType::cases(), 'value');

    foreach (['recording_started', 'recording_announcement', 'session_recorded'] as $absent) {
        expect($names)->not->toContain($absent);
    }
});

/*
 * ⚠️ AND ERASING A PERSON DOES NOT ERASE A RECORDING BY DEFAULT.
 *
 * A class recording holds OTHER PEOPLE — everyone else in the room. So the mode
 * for it is a decision the platform takes, not one the subject's request dictates,
 * and `ErasureMode` exists as three values precisely so that decision can be
 * expressed. Asserted as a property of the vocabulary rather than of a walk that
 * does not exist yet.
 */
it('keeps a retain mode available for data that holds third parties', function (): void {
    expect(ErasureMode::cases())->toHaveCount(3)
        ->and(ErasureMode::Retain->value)->toBe('retain');
});
