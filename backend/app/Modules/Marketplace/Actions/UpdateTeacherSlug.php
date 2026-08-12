<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;

/**
 * A teacher changing the segment their public profile lives at.
 *
 * The generated slug is a consonant skeleton — `ahmd-almnswry`, because Arabic
 * writes no short vowels and nothing can recover them from the script. This
 * Action is the reason the column is fillable: correcting it is the answer, not
 * a better transliterator.
 *
 * ⚠️ THE OLD SLUG STOPS WORKING, AND THERE IS NO REDIRECT FROM IT. Keeping one
 * would need a history table, which is a real feature with a real cost: every
 * past slug stays reserved for ever, so the first teacher to cycle through three
 * spellings has taken three names out of a shared namespace. The uuid URL is
 * unaffected and keeps resolving, which covers the links that predate slugs. The
 * form says so before the teacher presses save.
 */
class UpdateTeacherSlug extends Action
{
    public function handle(TeacherProfile $profile, string $slug): TeacherProfile
    {
        $profile->forceFill(['slug' => $slug])->save();

        // The teacher card is cached inside the home and list payloads, and it
        // now carries the slug. Without this the marketplace keeps linking to
        // the old one for a minute — a 404 on the page the teacher just renamed,
        // reached from the page they renamed it to be found from.
        MarketplaceCache::flush();

        return $profile;
    }
}
