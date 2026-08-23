<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Shared\Data\DataTransferObject;

/**
 * The courses an assistant is confined to.
 *
 * ⚠️ `$courseUuids === []` MEANS NO CONFINEMENT, never «no courses». There is no
 * `all_courses` flag anywhere in this feature for the same reason there is no
 * second column for anything else derived: two ways to say one thing disagree the
 * first time one of them is written and the other is not. An assistant is invited
 * before anybody decides what they will teach, so the empty case is the OPENING
 * state, and reading it as a refusal would lock every new assistant out of
 * everything on the day they accept.
 */
final class AssistantScopeData extends DataTransferObject
{
    /** @param  list<string>  $courseUuids */
    public function __construct(
        public readonly array $courseUuids,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $uuids = [];

        foreach ((array) ($data['courses'] ?? []) as $uuid) {
            if (is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return new self(array_values(array_unique($uuids)));
    }
}
