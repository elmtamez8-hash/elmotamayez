<?php

declare(strict_types=1);

namespace App\Modules\Courses\Rules;

use App\Modules\Courses\Support\EmbeddedVideoUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The closed host set, at the front door (032 · FR-002 · FR-004).
 *
 * ⚠️ THE FORM REQUEST IS THE FIRST HALF OF FR-004, NOT ALL OF IT. Seeders and
 * the panel reach `ManageLessons` with no form behind them, so the Action
 * canonicalises again and throws on a url this rule would have refused. Two
 * places, one predicate — {@see EmbeddedVideoUrl::build()} — never two spellings.
 *
 * ⚠️ AND IT IS DELIBERATELY NOT `required`, against the contract's first
 * wording. This repository checks completeness AT PUBLISH AND NEVER AT SAVE
 * (`PublishReadiness`'s own docblock says why): a draft is a half-written thing,
 * and refusing to save an embed item before its url is pasted means the teacher
 * cannot leave and come back — the one thing drafts exist for. `embed`'s
 * `required_to_publish` already carries `external_url`, so the requirement bites
 * at the moment the item becomes something a student can open, and not a moment
 * earlier. An empty value passes here; a WRONG one never does.
 */
final class AcceptedEmbedUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || ! EmbeddedVideoUrl::accepts($value)) {
            $fail(EmbeddedVideoUrl::refusal());
        }
    }
}
