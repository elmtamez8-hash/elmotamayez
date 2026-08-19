<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Data\IssuedVerification;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\PhoneNumber;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues a one-time code proving a contact detail belongs to this account
 * (FR-041).
 *
 * Requesting again for the same channel invalidates whatever came before — both
 * the previous code and any earlier verification of a different value (FR-042).
 * Otherwise changing a phone number would leave the old one verified and still
 * receiving.
 */
class RequestContactVerification extends Action
{
    public function handle(User $user, NotificationChannel $channel, string $contactValue): IssuedVerification
    {
        // Normalised HERE, where the value is written, and never where it is sent
        // (spec 020, FR-016). A channel that cleans up its own input carries that
        // cleanup for ever, and the column ends up holding `+97433123456`,
        // `033123456` and `974 3312 3456` as three different people.
        //
        // Refused rather than guessed: a number we could not read is a message to
        // a stranger, and the person who typed it hears nothing and assumes it
        // worked.
        if ($channel->isPhoneNumber()) {
            $contactValue = PhoneNumber::toE164(
                $contactValue,
                (string) config('notifications.default_country_code'),
            ) ?? throw ValidationException::withMessages([
                'contact_value' => 'رقم الهاتف غير صالح. اكتبه بصيغة دولية مثل ‎+97433123456‎.',
            ]);
        }

        // Everything prior for this channel goes, verified or not. A user who
        // moves their number must not stay reachable at the old one.
        //
        // ⚠️ AND SINCE SPEC 020 THAT HAS A COST WORTH KNOWING ABOUT. Until then
        // nothing sent the code, so "request again" could not fail. Now it can:
        // a verified user asks to change their number, the provider is down, the
        // request 422s — and the row proving the OLD number is already gone, so
        // every delivery to them is silently `skipped` until they try again.
        //
        // Kept, because the alternative is worse in the case that matters: a
        // number is usually changed BECAUSE it stopped being theirs, and keeping
        // the old one verified until a new one is proven means messaging a
        // stranger about someone's child. The mitigation is honesty rather than
        // retention — the failure the caller shows says the old number is no
        // longer confirmed, instead of leaving the user to discover it from
        // silence.
        ContactVerification::query()
            ->where('user_id', $user->getKey())
            ->where('channel', $channel->value)
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $verification = ContactVerification::query()->create([
            'user_id' => $user->getKey(),
            'channel' => $channel->value,
            'contact_value' => $contactValue,
            // Hashed. A table of plaintext one-time codes is a password table
            // under another name, readable from any database backup.
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes((int) config('notifications.verification.ttl_minutes')),
        ]);

        // The code is handed back to the caller, never persisted in the clear. In
        // production the caller passes it to the channel being verified; there is
        // no channel to pass it to yet, so nothing is sent (research R12).
        return new IssuedVerification($verification, $code);
    }
}
