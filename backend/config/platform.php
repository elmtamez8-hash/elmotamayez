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

];
