<?php

declare(strict_types=1);

return [
    /*
    | VAPID — the key pair that identifies THIS server to a push service
    | (spec 012 · US2).
    |
    | ⚠️ NOT `platform_settings`, and that is a deliberate exception to this
    | repository's own rule that operational numbers live in the panel. The rule
    | is about NUMBERS an operator tunes; a settings row is readable by everyone
    | who can open `/admin`, so a signing key there widens who can mint a valid
    | push identity from «whoever administers the server» to «whoever administers
    | a workspace». Same line spec 019 drew for the CDN signing key.
    |
    | ⚠️ AND NOT ONE OF THE THREE HAS A DEFAULT VALUE HERE. A fallback in this
    | file is a private key committed to the repository — the whole point of
    | reading them from the environment. Unset, `WebPushChannel` has nothing to
    | sign with and refuses to send; that is the correct behaviour for a
    | deployment that has not been given keys, and it is loud rather than silent
    | because `canReach()` is what decides, not a swallowed provider error.
    |
    | `public` is not a secret: the BROWSER needs it to subscribe at all, and the
    | frontend reads it as NEXT_PUBLIC_VAPID_PUBLIC_KEY. Only `private` signs.
    |
    | `subject` is the `sub` claim of the VAPID JWT — a `mailto:` or `https:`
    | URL the push service can use to reach whoever operates this server.
    */
    'vapid' => [
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],
];
