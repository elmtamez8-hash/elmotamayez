<?php

declare(strict_types=1);

/*
| Spec 010 — the broadcast channel authorisation map.
|
| ⚠️ THIS FILE IS THE ONLY PLACE A CHANNEL NAME IS ENUMERATED, and that is the
| whole of `NFR-007`. Loaded through `channels:` in `bootstrap/app.php`, which is
| also what creates `/broadcasting/auth` — without that line there is no
| authorisation endpoint at all and every subscription fails with nothing saying
| why.
|
| ⚠️ AND EVERY CALLBACK HERE CALLS THE ACTION'S OWN GUARD, never a second
| condition written beside it. Two spellings of "may this person read this
| conversation" put one answer on the screen and another at the door — the defect
| this repository has already recorded for `BookingEligibility` and for
| `ListLeaderboardScopes`.
|
| ⚠️ AUTHORISATION HAPPENS ONCE, AT SUBSCRIBE, AND THE PROTOCOL HAS NO REVOCATION.
| An assistant whose permission is withdrawn while still connected keeps receiving
| events on a channel they were admitted to. What makes that tolerable is that the
| payload is an IDENTIFIER and nothing else: the fetch that follows goes through
| the authenticated route and is refused there. That is a second reason for the
| id-only rule, and it is written down in both places on purpose.
|
| Laravel's default `App.Models.User.{id}` channel was removed rather than left:
| this product's notifications are rows in our own table read over HTTP, the
| Filament panel does not enable broadcast notifications, and a channel nobody
| publishes to is a subscription surface with no owner.
*/
