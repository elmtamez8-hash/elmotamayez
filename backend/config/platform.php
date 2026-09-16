<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The product's name
    |--------------------------------------------------------------------------
    |
    | ⚠️ NOT `env()`, AND THAT IS THE WHOLE POINT OF THIS FILE. The name used to
    | live in `NEXT_PUBLIC_PLATFORM_NAME`, which is inlined at BUILD time and was
    | set nowhere — so every title, header and footer on the live site read
    | «منصّتي», a placeholder, for months. A value nobody can change without a
    | deploy is a value nobody changes; a value that DEGRADES to a placeholder
    | when an environment variable is missing degrades in silence.
    |
    | It is a `platform_settings` row now, editable from `/admin/platform-settings`,
    | and this line is the fallback for a database with nothing seeded — the
    | precedent every operational value in this product has followed since 006.
    | The fallback is the REAL name, never a placeholder: a missing row must look
    | like the product, not like an unfinished one.
    |
    | `PRODUCT.md` records «المتميز» as a binding brand commitment, which is why
    | it is hard-coded here rather than read from anywhere else.
    |
    */

    'name' => 'المتميز',

    /*
    |--------------------------------------------------------------------------
    | The support number on WhatsApp
    |--------------------------------------------------------------------------
    |
    | Digits only, in E.164 without the leading "+" (e.g. 97455512345), because
    | that is the shape `wa.me/<number>` takes. Stored that way rather than
    | normalised on read: one spelling written once beats a strip at every
    | reader.
    |
    | ⚠️ IT LIVED IN `NEXT_PUBLIC_WHATSAPP_NUMBER`, WHICH IS THE `platform.name`
    | DEFECT WEARING A SECOND FACE. A `NEXT_PUBLIC_*` variable is inlined at
    | BUILD time and was set nowhere, so the floating button rendered for nobody
    | — and nothing failed anywhere, because an empty number is also how the
    | button is switched OFF on purpose.
    |
    | Empty is the fallback and it means OFF, deliberately. There is no sensible
    | placeholder: a `wa.me` link with an invented number opens a stranger's
    | chat, and every visitor who taps it reaches a real person who never agreed
    | to it.
    |
    */

    'support_whatsapp' => '',

];
