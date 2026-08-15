<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * Bloom's cognitive level, one of the four mandatory tags on a bank question.
 *
 * A closed list rather than free text, because this tag is the axis spec 012's
 * adaptive path will select on: free text produces "تطبيق" and "التطبيق" and
 * "تطبيقي" from three teachers and no query can group them.
 *
 * `Unclassified` exists for the same reason the "غير مصنّف" concept row does.
 * Questions authored before 008 have no cognitive level, and the alternatives
 * are both worse: a nullable column makes "mandatory" a lie in half the rows
 * (FR-002), and guessing a level for someone else's question is fabricating
 * pedagogical data. An explicit case is a filter a teacher can see and fix.
 */
enum BloomLevel: string
{
    case Unclassified = 'unclassified';
    case Remember = 'remember';
    case Understand = 'understand';
    case Apply = 'apply';
    case Analyze = 'analyze';
    case Evaluate = 'evaluate';
    case Create = 'create';
}
