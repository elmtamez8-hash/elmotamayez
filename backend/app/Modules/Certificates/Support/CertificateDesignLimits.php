<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The two numbers an uploaded certificate design is measured against.
 *
 * ⚠️ READ FROM `platform_settings`, NEVER A CONSTANT IN THIS FILE. This repo's
 * rule: a limit that can only change by shipping code is a limit nobody ever
 * tunes — the same reason the device limit, the grant TTL and every media
 * ceiling live in that table. The literals below are the fallback for a database
 * with no row seeded, exactly as `config/media.php` is for `MediaLimits`.
 *
 * ⚠️ AND THE ANNOUNCED LIMIT AND THE ENFORCED ONE ARE ONE READ. `UploadDesignRequest`
 * puts the number into its Arabic refusal and `CertificateDesignController@index`
 * puts it on the screen beside the counter; two spellings would eventually tell a
 * teacher one number and refuse them at another.
 */
final class CertificateDesignLimits
{
    /** How many uploaded designs one workspace may hold (`FR-046`). */
    public static function uploadLimit(): int
    {
        return (int) PlatformSettings::get('certificates.design_upload_limit', 5);
    }

    /** The ceiling the request validates against, in kilobytes (Laravel's `max:` unit). */
    public static function maxSizeKilobytes(): int
    {
        return (int) PlatformSettings::get('certificates.design_max_size_kb', 4096);
    }

    /**
     * The formats accepted, as the teacher reads them and as `mimes:` reads them.
     *
     * One list, because a refusal naming formats the validator does not accept is
     * worse than a silent one: it sends somebody to convert a file that will be
     * refused again.
     *
     * @return list<string>
     */
    public static function formats(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }
}
