<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Community's half of the data-rights contract (spec 013 · NFR-004 · SC-004).
 *
 * ⚠️ THIS MODULE WAS OUTSIDE THE MACHINERY FROM PHASE 1 AND THE GUARD REPORTED
 * GREEN. `PersonalDataContractCoverageTest` detects a personal column by looking
 * for `constrained('users')` — and every Community migration before the report
 * card writes `unsignedBigInteger('sender_user_id')` instead, which is the other
 * way this repository declares the same foreign key. So an erasure request
 * completed GREEN while leaving every private message with a minor, every
 * moderation note about them and every assessment of them exactly where it was.
 * The detector is widened in the same change; nothing else in the tree lit up,
 * because every other module was already registered.
 *
 * ⚠️ AND THE REPORT CARD IS PLATFORM-OWNED, so its rows are found by
 * `student_user_id` with no workspace anywhere in the predicate. Scoping any
 * query here would erase one teacher's share of a person's record and report the
 * count as though it were all of it.
 *
 * @see PersonalDataOwner
 */
class CommunityPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'community';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['chat_message', 'periodic_review', 'report_card'];
    }

    /** @return iterable<string, array<int, array<string, mixed>>> */
    public function export(DataSubject $subject): iterable
    {
        $userId = $subject->user->getKey();

        /*
        | ⚠️ MESSAGES THIS PERSON SENT, NEVER THE THREADS THEY SAT IN. A private
        | conversation has two sides, and exporting the other person's words
        | because they were addressed to this one hands over somebody else's
        | data under the cover of a rights request — the requester is entitled to
        | what is theirs, not to a transcript of a third party.
        |
        | Gated on `Results` for a guardian: a chat with a teacher is where a
        | student says what they did not understand, and an attendance-only
        | guardian was granted the register and not the confidence.
        */
        if ($subject->mayReceive(GuardianPermission::Results)) {
            yield from ExportWalk::keyed(
                'chat_message',
                Message::query()
                    ->withoutGlobalScopes()
                    ->where('sender_user_id', $userId)
                    ->select(['messages.*']),
                fn (Message $message): array => [
                    'uuid' => $message->uuid,
                    'body' => $message->body,
                    'hidden_at' => ExportWalk::at($message->hidden_at),
                    'sent_at' => ExportWalk::at($message->created_at),
                ],
                column: 'messages.id',
            );
        } else {
            yield from ExportWalk::none('chat_message');
        }

        if (! $subject->mayReceive(GuardianPermission::Results)) {
            yield from ExportWalk::none('periodic_review', 'report_card');

            return;
        }

        /*
        | ⚠️ ONLY PUBLISHED ASSESSMENTS. A draft is a note the teacher has not
        | stood behind, and handing one over through a rights request is a way of
        | reading it that the product refuses everywhere else — the student's own
        | endpoint filters exactly this.
        */
        yield from ExportWalk::keyed(
            'periodic_review',
            PeriodicReview::query()
                ->withoutGlobalScopes()
                ->where('student_user_id', $userId)
                ->whereNotNull('published_at')
                ->select(['periodic_reviews.*']),
            fn (PeriodicReview $review): array => [
                'uuid' => $review->uuid,
                'period_start' => $review->period_start->toDateString(),
                'period_end' => $review->period_end->toDateString(),
                'commitment' => $review->commitment,
                'participation' => $review->participation,
                'homework' => $review->homework,
                'improvement' => $review->improvement,
                'note' => $review->note,
                'published_at' => ExportWalk::at($review->published_at),
            ],
            column: 'periodic_reviews.id',
        );

        yield from ExportWalk::keyed(
            'report_card',
            ReportCard::query()
                ->where('student_user_id', $userId)
                ->whereNotNull('published_at')
                ->with('segments')
                ->select(['report_cards.*']),
            fn (ReportCard $card): array => [
                'uuid' => $card->uuid,
                'period_start' => $card->period_start->toDateString(),
                'period_end' => $card->period_end->toDateString(),
                'overall_pct' => $card->overall_pct,
                'improvement_index' => $card->improvement_index,
                'segments' => $card->segments
                    ->map(fn (ReportCardSegment $segment): array => [
                        'components' => $segment->components,
                        'attendance_pct' => $segment->attendance_pct,
                        'segment_pct' => $segment->segment_pct,
                    ])
                    ->all(),
            ],
            column: 'report_cards.id',
        );
    }

    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        $userId = $subject->user->getKey();

        $messages = Message::query()
            ->withoutGlobalScopes()
            ->where('sender_user_id', $userId)
            ->limit($limit)
            ->delete();

        if ($messages >= $limit) {
            return $messages;
        }

        $reviews = PeriodicReview::query()
            ->withoutGlobalScopes()
            ->where('student_user_id', $userId)
            ->limit($limit - $messages)
            ->delete();

        if ($messages + $reviews >= $limit) {
            return $messages + $reviews;
        }

        /*
        | ⚠️ THE SEGMENTS GO BEFORE THE CARDS. They carry `student_user_id` of
        | their own — the duplication the constitution asks a bridge for — so they
        | are reachable directly; deleting the cards first would rely on the
        | database cascade, which is a second erasure path with a different count
        | and no way for a resumable walk to report what it did.
        */
        $segments = ReportCardSegment::query()
            ->withoutGlobalScopes()
            ->where('student_user_id', $userId)
            ->limit($limit - $messages - $reviews)
            ->delete();

        $done = $messages + $reviews + $segments;

        if ($done >= $limit) {
            return $done;
        }

        return $done + $this->deleteCards(
            ReportCard::query()->where('student_user_id', $userId),
            $limit - $done,
        );
    }

    /**
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($mode !== ExpiryBehaviour::Delete) {
            return 0;
        }

        // ⚠️ A DATE COMPUTED IN PHP AND COMPARED AS A STRING. `whereDate()` wraps
        // the column and throws away its index, and `created_at + INTERVAL n DAY`
        // in SQL raises ERROR 1441 past year 9999 on MySQL while SQLite silently
        // returns NULL and expires nothing.
        $cutoff = $before->toDateTimeString();

        if ($category === 'chat_message') {
            return Message::query()
                ->withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('sender_user_id', $exemptUserIds))
                ->limit($limit)
                ->delete();
        }

        if ($category === 'periodic_review') {
            return PeriodicReview::query()
                ->withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
                ->limit($limit)
                ->delete();
        }

        if ($category !== 'report_card') {
            return 0;
        }

        $segments = ReportCardSegment::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit)
            ->delete();

        if ($segments >= $limit) {
            return $segments;
        }

        return $segments + $this->deleteCards(
            ReportCard::query()
                ->where('created_at', '<', $cutoff)
                ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds)),
            $limit - $segments,
        );
    }

    /**
     * ⚠️ CARDS ARE DELETED THROUGH THE MODEL, NOT BY A BULK `delete()`. Each one
     * owns a rendered PDF in medialibrary, and a bulk delete retrieves no models
     * — so the row goes and the file carrying a named minor's grades stays at the
     * provider for ever, billed monthly, referenced by nothing. The same fact
     * that makes `LedgerEntry`'s append-only guard bypassable by a bulk update.
     *
     * @param  Builder<ReportCard>  $query
     */
    private function deleteCards(Builder $query, int $limit): int
    {
        $deleted = 0;

        foreach ($query->limit($limit)->get() as $card) {
            $card->delete();
            $deleted++;
        }

        return $deleted;
    }
}
