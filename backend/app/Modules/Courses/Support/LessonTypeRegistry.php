<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Media\Enums\MediaKind;
use DomainException;

/**
 * The single source of truth for what each content type IS.
 *
 * Four facts per type — its family, whether it can be completed, which fields it
 * needs before it may be published, and which kind of asset it expects. Every
 * one of those is consulted from more than one place: validation, the progress
 * denominator, the sequential gate, the tree resource and the editor. Copying
 * any of them into a FormRequest is how the answer starts differing by caller.
 *
 * Completability is not a preference. A type that cannot be completed stays out
 * of the progress denominator and never blocks what follows it (FR-012) —
 * otherwise a notice nobody is asked to open would sit between a student and
 * the rest of their course, and a percentage would never reach 100.
 */
final class LessonTypeRegistry
{
    public const FAMILY_INLINE = 'inline';

    public const FAMILY_UPLOADED = 'uploaded';

    public const FAMILY_REFERENCE = 'reference';

    public const FAMILY_EXTERNAL = 'external';

    /**
     * @var array<string, array{
     *     family: string,
     *     completable: bool,
     *     asset_kind: MediaKind|null,
     *     required_to_publish: list<string>,
     *     implemented: bool,
     * }>
     */
    private const MAP = [
        'article' => [
            'self_completable' => true,
            'family' => self::FAMILY_INLINE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['content'],
            'implemented' => true,
        ],
        'note' => [
            'self_completable' => false,
            'family' => self::FAMILY_INLINE,
            // Nothing is asked of the reader, so nothing can be completed. It
            // also must not gate: a course would stall on an unread notice.
            'completable' => false,
            'asset_kind' => null,
            'required_to_publish' => ['content'],
            'implemented' => true,
        ],
        'video' => [
            'self_completable' => true,
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Video,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'audio' => [
            'self_completable' => true,
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Audio,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'pdf' => [
            'self_completable' => true,
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Document,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'file' => [
            'self_completable' => true,
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Document,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'exam' => [
            'self_completable' => false,
            'family' => self::FAMILY_REFERENCE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['reference_id'],
            'implemented' => true,
        ],
        'assignment' => [
            // ⚠️ `false`, like `exam` and for the same reason: the item is
            // completed by the EVIDENCE — `CompleteAssignmentLessonOnSubmission`
            // writes the progress row when the homework is actually handed in.
            // A self-declare button here would be «I did my homework» without
            // handing any in. See `isSelfCompletable()`.
            'self_completable' => false,
            'family' => self::FAMILY_REFERENCE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['reference_id'],
            'implemented' => true,
        ],
        'live_session' => [
            'self_completable' => false,
            'family' => self::FAMILY_REFERENCE,
            // A session that has not happened cannot be completed, and the
            // recording that replaces it answers to a seat rather than to
            // enrolment — so it stays out of the denominator either way.
            'completable' => false,
            'asset_kind' => null,
            'required_to_publish' => ['reference_id'],
            'implemented' => true,
        ],
        'link' => [
            'self_completable' => false,
            'family' => self::FAMILY_EXTERNAL,
            // The platform cannot know what the student did on someone else's
            // site, so "I finished it" would be a button pressed by people who
            // never opened it — and a gate opened by pressing it.
            'completable' => false,
            'asset_kind' => null,
            'required_to_publish' => ['external_url'],
            'implemented' => true,
        ],
        'embed' => [
            // ⚠️ `true`, UNLIKE ITS SIBLING `link` IN THE SAME FAMILY, and the
            // difference is what is being asked of the reader. A `link` sends
            // the student to somebody else's site and the platform cannot know
            // what they did there. An `embed` plays INSIDE our page — it is the
            // uploaded video with a different host behind the frame, so it is
            // completed the same way (FR-014).
            'self_completable' => true,
            'family' => self::FAMILY_EXTERNAL,
            // ⛔ AN ITEM THAT ENTERS THE DENOMINATOR AND CAN NEVER BE COMPLETED
            // caps every enrolled student below 100% for ever, so
            // `CourseCompleted` never fires and no certificate ever issues. That
            // is the worst defect this repository records, and it is not created
            // for a saving in bandwidth.
            'completable' => true,
            // No asset, no bytes, no grant — SC-001 is measured on this line.
            'asset_kind' => null,
            // ⛔ AND THIS IS THE BACK DOOR, not a benign detail.
            // `ChangeLessonType` carries `external_url` verbatim when the target
            // type asks for it, and `link` accepts ANY https url — so
            // «create a link → change it to embed → publish» would plant free
            // text in an `<iframe src>` on our own domain. The canonicaliser
            // therefore runs on the TRANSITION too, not in `ManageLessons` alone.
            'required_to_publish' => ['external_url'],
            'implemented' => true,
        ],
    ];

    public static function family(LessonType $type): string
    {
        return self::MAP[$type->value]['family'];
    }

    public static function isCompletable(LessonType $type): bool
    {
        return self::MAP[$type->value]['completable'];
    }

    /**
     * Whether the STUDENT may declare this item finished, as opposed to the
     * system concluding it from evidence.
     *
     * ⚠️ A NARROWER QUESTION THAN {@see isCompletable}, AND THE GAP IS `exam`.
     * An exam item counts in the denominator and is completed by
     * `CompleteExamLessonOnSubmission` when the paper is actually sat — so a
     * self-declare control on it is a button that walks a student past the exam
     * and moves their percentage without answering a question. It is refused at
     * the door as well as hidden on the screen: hiding a control is not a guard.
     *
     * ⚠️ AND `assignment` IS `false` FOR THE SAME REASON AS `exam` — AND ONLY
     * BECAUSE ITS WRITER EXISTS. Until the curriculum could place homework, this
     * was `true` on purpose: `AssignmentSubmitted` had one listener and it sent a
     * notification, so nothing anywhere completed an assignment item, and
     * grouping it with `exam` would have made every such item permanently
     * incompletable — every enrolled student capped below 100%, no
     * `CourseCompleted`, no certificate, for ever. The item now has two writers
     * in Learning, exactly as `exam` does: `CompleteAssignmentLessonOnSubmission`
     * (a hand-in from now on) and `CompleteAssignmentLessonsAlreadySubmitted`
     * (the homework handed in BEFORE the teacher placed it in the tree). Remove
     * either and this flag must go back to `true` in the same change.
     *
     * `note`, `live_session` and `link` are false because they are not
     * completable at all; the two lists agree there and that is not a
     * coincidence — nothing uncountable can be declared done.
     */
    public static function isSelfCompletable(LessonType $type): bool
    {
        return self::MAP[$type->value]['self_completable'];
    }

    public static function assetKind(LessonType $type): ?MediaKind
    {
        return self::MAP[$type->value]['asset_kind'];
    }

    /** @return list<string> */
    public static function requiredToPublish(LessonType $type): array
    {
        return self::MAP[$type->value]['required_to_publish'];
    }

    public static function isImplemented(LessonType $type): bool
    {
        return self::MAP[$type->value]['implemented'];
    }

    /**
     * Refuses a declared-but-unbuilt type, by name.
     *
     * Here rather than in each Action: `ManageLessons::create` and
     * `ChangeLessonType::handle` are two doors onto the same decision and each held
     * a byte-identical copy of the sentence. The registry owns `implemented`, so it
     * owns what to say when the answer is false — "this type is not available
     * yet" is an answer; "invalid type" sends the teacher to look for their own
     * mistake.
     *
     * @throws DomainException
     */
    public static function assertImplemented(LessonType $type): void
    {
        if (self::isImplemented($type)) {
            return;
        }

        // No type is unbuilt today — `assignment` was the last. The door stays
        // so the next declared-before-built type is refused in words rather
        // than saved as an item that does nothing.
        throw new DomainException('هذا النوع من العناصر غير متاح بعد. اختر نوعاً آخر لهذا العنصر.');
    }

    /**
     * The types that count towards a course's progress.
     *
     * Returned as raw strings because the caller is a query builder: the
     * denominator is a `whereIn` on the `type` column, not a loop over models.
     *
     * @return list<string>
     */
    public static function completableValues(): array
    {
        return array_keys(array_filter(
            self::MAP,
            static fn (array $row): bool => $row['completable'],
        ));
    }
}
