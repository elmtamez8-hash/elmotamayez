<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

/**
 * The course's subject, in the one shape the public side publishes it.
 *
 * ⚠️ EXTRACTED THE DAY A SECOND RESOURCE NEEDED IT, NOT BEFORE. It lived as a
 * private method on {@see PublicCourseDetailResource} while one caller had it,
 * which was right; the card needs it now for its generated cover, and a copied
 * three-key array is the two-spellings defect this tree records a dozen times —
 * they agree today and diverge at the first key either side adds.
 *
 * ⚠️ AND IT IS A RELATION READ, SO THE CALLER EAGER-LOADS IT. A Resource runs
 * once per row, so `$this->subject` inside one is an N+1 by construction
 * (`ClassSessionResource`'s own lesson). The three public queries that build
 * cards — `ListPublicCourses`, `ShowPublicTeacher::coursesOf()` and
 * `RelatedTeachers` — name `subject` in their `with()` for that reason.
 *
 * `null` is a real answer: `courses.subject_id` is nullable and a course with no
 * subject still has a page and a card. The cover falls back to its own mark
 * rather than drawing nothing.
 */
trait PublishesSubject
{
    /** @return array{slug: string, name: string, icon: string|null}|null */
    private function subjectShape(): ?array
    {
        $subject = $this->subject;

        if ($subject === null) {
            return null;
        }

        return [
            'slug' => (string) $subject->slug,
            'name' => (string) $subject->name,
            // The operator's lever, and it reaches the client unread by us: the
            // frontend maps a name it knows to an icon and falls back for one it
            // does not. See `subject-icon.ts`, which carries both maps.
            'icon' => $subject->icon,
        ];
    }
}
