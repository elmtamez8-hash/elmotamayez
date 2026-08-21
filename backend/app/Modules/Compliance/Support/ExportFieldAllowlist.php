<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Shared\Contracts\PersonalDataOwner;

/**
 * What may never appear inside an export archive, at any depth (FR-017 · SC-005).
 *
 * ⚠️ THIS IS NOT A SEVENTH FIELD LIST, AND THAT DISTINCTION IS THE WHOLE DESIGN.
 * Six allowlists ship today — `AssessmentFieldAllowlist`, `MediaFieldAllowlist`,
 * `PaymentFieldAllowlist`, `PublicFieldAllowlist`, `StudentBalanceAllowlist`,
 * `TeacherFieldAllowlist` — and four of them are owned by the module whose columns
 * they describe. A central list naming other modules' fields would be a second
 * answer to a question those four already answer, and the second answer is the one
 * that stops being updated: a column renamed in `Assessments` would leave a
 * `Compliance` constant pointing at nothing, silently exporting less than the right
 * requires while every test stayed green.
 *
 * So each implementor of {@see PersonalDataOwner} writes its own explicit key list
 * inside `export()` — composing its module allowlist where one exists, and an
 * inline array where none does. That inline array IS that module's allowlist, and
 * it is reviewed in the module's own file by the people who own the table.
 *
 * ⚠️ WHAT IS LEFT FOR HERE IS WHAT NO MODULE OWNS: names that must not travel
 * whoever the subject is and whichever module produced the row. Every entry below
 * is universal in that sense, which is why the list is short — a key that is
 * forbidden for a student and legitimate for a teacher (a settlement rate, an
 * amount) belongs in the module's own decision, not in a blanket ban that would
 * silently strip a departing teacher's own earnings from their own copy.
 */
final class ExportFieldAllowlist
{
    /**
     * Key names that must never appear in an export, at any depth.
     *
     * Matched as SUBSTRINGS, so `code_hash` also catches `verification_code_hash`.
     *
     * Two entries carry a reason that is not obvious:
     *
     * - `receipt` — a manual-transfer receipt is an image a HUMAN uploaded, and it
     *   routinely carries the name and account number of whoever holds the bank
     *   account, who is frequently a third party. Its existence and its date are
     *   exported; the file and its path are not. Putting a picture nobody has read
     *   into an archive we hand over is publishing content of unknown contents.
     * - `provider_asset_id` — FR-011 keeps the media provider's own identifier out
     *   of every payload, and an export is a payload with a longer life than any
     *   other.
     *
     * `ip_address`, `ip_hash` and `user_agent` are deliberately ABSENT. They record
     * the subject's own device at their own consent or sign-in — their data, and in
     * the case of `terms_consents.ip_address` the evidence that the consent was
     * theirs. Banning them would remove from a person's copy the one field that
     * proves what they agreed to.
     *
     * @return list<string>
     */
    public static function forbiddenKeys(): array
    {
        return [
            'password',
            'remember_token',
            'code_hash',
            'secret',
            'api_key',
            'private_key',
            'signature',
            'token',
            'receipt',
            'provider_asset_id',
        ];
    }

    /**
     * Everything wrong with this block, by path. Empty means clean.
     *
     * The same shape as `PaymentFieldAllowlist::leaks()` — named in prose rather
     * than as a `@see`, so this module imports nothing from `Payments` for a
     * comment's sake.
     *
     * @return list<string>
     */
    public static function leaks(mixed $payload, string $path = ''): array
    {
        $found = [];

        if (! is_array($payload)) {
            return $found;
        }

        foreach ($payload as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && self::isForbidden($key)) {
                $found[] = 'forbidden key: '.$here;

                continue;
            }

            $found = array_merge($found, self::leaks($value, $here));
        }

        return $found;
    }

    /**
     * The same block with every forbidden key removed, at any depth.
     *
     * ⚠️ STRIPPED RATHER THAN REFUSED, AND THE REMOVAL IS LOGGED BY THE CALLER.
     * Throwing would let one bad key in one module fail a LEGAL request for the
     * whole archive, which is a worse outcome than the leak it prevents; silently
     * dropping would hide the defect for ever. So the archive is delivered without
     * it and the operator has a line naming the path, which is what gets the module
     * fixed. {@see self::leaks()} is what fails the build.
     */
    public static function strip(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isForbidden($key)) {
                continue;
            }

            $clean[$key] = self::strip($value);
        }

        return $clean;
    }

    private static function isForbidden(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::forbiddenKeys() as $forbidden) {
            if (str_contains($needle, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
