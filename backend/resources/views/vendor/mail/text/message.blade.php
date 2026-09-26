{{--
| Published from the framework for three reasons, and only for them (docs/gotchas/notifications.md):
| the product is Arabic-only, so the document is `dir="rtl"`; the name is the
| `platform.name` row an operator edits, not `APP_NAME`; and the link in the
| header is the FRONTEND, not the API host. Every string is a key in `lang/ar.json`.
--}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('cms.site_url')">
            {{ \App\Modules\Tenancy\Support\PlatformSettings::get('platform.name') ?: config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ \App\Modules\Tenancy\Support\PlatformSettings::get('platform.name') ?: config('app.name') }}. @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
