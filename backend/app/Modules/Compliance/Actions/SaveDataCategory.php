<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Create or update one catalogue entry.
 *
 * ⚠️ THE BOUNDS ARE ENFORCED HERE, NOT ONLY IN THE FORM REQUEST, and this phase
 * has two doors that skip validation entirely: `SeedCommand` runs every seeder
 * inside `Model::unguarded()`, and the Filament panel writes without a
 * `FormRequest` at all. A rule that lives only in validation is a rule with two
 * known bypasses — Constitution II says the Action is the single entrance the
 * seeders, the panel and the API all share.
 */
class SaveDataCategory extends Action
{
    /**
     * Categories whose retention may not go below a floor of their own, and why.
     *
     * ⛔ SPEC 038 — `playback_grants.issued_ip_hash` HOLDS THE SAME VALUE AS
     * `auth_sessions.ip_hash` and the row is indexed on `auth_session_id`, so one
     * join recovers the address of a session declared addressless. That table has
     * no catalogue row and no sweep; its only cleaner is `PruneExpiredGrantsJob`,
     * at `expires_at + 7 days`. So while the retention is eight days or more,
     * every grant belonging to a session old enough to be anonymised has already
     * been pruned — the gap is closed BY CONSTRUCTION rather than by luck.
     *
     * ⚠️ AND `minRetainDays()` DEFAULTS TO 1, so without this the operator can
     * open that window from the settings screen with one keystroke. Owner
     * decision, 2026-09-22; the wider hole is recorded in the spec's out-of-scope
     * list and is somebody else's phase.
     *
     * @var array<string, int>
     */
    private const FLOOR_DAYS = [
        'auth_session' => 8,
        'device' => 8,
    ];

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, ?DataCategory $category = null): DataCategory
    {
        $this->guardRetention($attributes, $category?->key);

        if ($category === null) {
            return DataCategory::query()->create($attributes);
        }

        $category->fill($attributes)->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardRetention(array $attributes, ?string $existingKey = null): void
    {
        $days = $attributes['retain_days'] ?? null;

        if ($days === null) {
            return;
        }

        $days = (int) $days;

        $key = $attributes['key'] ?? $existingKey;
        $floor = is_string($key) ? (self::FLOOR_DAYS[$key] ?? null) : null;

        if ($floor !== null && $days < $floor) {
            throw new DomainException(
                'مدّةُ الاحتفاظ لسجلّ الجلسات والأجهزة لا تقلّ عن '.$floor.' أيّام: '
                .'منحُ التشغيل تحمل العنوان نفسه وتُكنَس بعد انتهائها بأسبوع، فمدّةٌ أقصر تُبقي الاثنين في نافذة واحدة.',
            );
        }

        /*
        | ⚠️ ZERO IS THE ONE TYPO THAT ERASES THE PLATFORM IN A NIGHT. It reads as
        | "no retention" and means "everything older than this instant", which is
        | everything — and the sweep is not reversible.
        */
        if ($days < ComplianceSettings::minRetainDays()) {
            throw new DomainException('مدّةُ الاحتفاظ لا تقلّ عن يومٍ واحد: الصفرُ ليس «فوراً» بل محوُ كلّ ما مضى في ليلة.');
        }

        /*
        | ⚠️ AND THE CEILING IS THE COLUMN, NOT A PREFERENCE. `unsignedSmallInteger`
        | stops at 65,535; past it `created_at + n days` runs off the end of the
        | calendar and MySQL raises ERROR 1441, which kills the whole sweep — every
        | other category included. SQLite returns NULL instead and nothing expires,
        | so neither failure appears on a development machine.
        */
        if ($days > ComplianceSettings::maxRetainDays()) {
            throw new DomainException('أقصى مدّةِ احتفاظٍ ٦٥٥٣٥ يوماً (‏نحو ١٧٩ سنة). اتركِ الحقلَ فارغاً إن كان الصنفُ لا ينقضي.');
        }
    }
}
