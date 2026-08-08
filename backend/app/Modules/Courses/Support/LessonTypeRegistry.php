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
            'family' => self::FAMILY_INLINE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['content'],
            'implemented' => true,
        ],
        'note' => [
            'family' => self::FAMILY_INLINE,
            // Nothing is asked of the reader, so nothing can be completed. It
            // also must not gate: a course would stall on an unread notice.
            'completable' => false,
            'asset_kind' => null,
            'required_to_publish' => ['content'],
            'implemented' => true,
        ],
        'video' => [
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Video,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'audio' => [
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Audio,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'pdf' => [
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Document,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'file' => [
            'family' => self::FAMILY_UPLOADED,
            'completable' => true,
            'asset_kind' => MediaKind::Document,
            'required_to_publish' => ['asset'],
            'implemented' => true,
        ],
        'exam' => [
            'family' => self::FAMILY_REFERENCE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['reference_id'],
            'implemented' => true,
        ],
        'assignment' => [
            'family' => self::FAMILY_REFERENCE,
            'completable' => true,
            'asset_kind' => null,
            'required_to_publish' => ['reference_id'],
            // Spec 008 owns the assignment entity. Declared here so the tree is
            // designed once; rejected in the Action by name until then.
            'implemented' => false,
        ],
        'live_session' => [
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
            'family' => self::FAMILY_EXTERNAL,
            // The platform cannot know what the student did on someone else's
            // site, so "I finished it" would be a button pressed by people who
            // never opened it — and a gate opened by pressing it.
            'completable' => false,
            'asset_kind' => null,
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
     * owns what to say when the answer is false — "assignments arrive with the
     * question bank" is an answer; "invalid type" sends the teacher to look for
     * their own mistake.
     *
     * @throws DomainException
     */
    public static function assertImplemented(LessonType $type): void
    {
        if (self::isImplemented($type)) {
            return;
        }

        throw new DomainException(
            'الواجبات لم تُفعَّل بعد — تصل مع بنك الأسئلة. اختر نوعاً آخر لهذا العنصر.',
        );
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
