<x-filament-panels::page>
    {{-- Spec 011 · FR-040. Numbers only — every one of them is a row of
         `platform_metrics_daily`, and the numerator and denominator are printed
         beside a ratio so the screen can be compared against the source. --}}
    <p class="fi-section-header-description">
        أرقام يوم {{ $report['date'] ?? '—' }} — تُحدَّث بتجميع ليليّ.
    </p>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($report['metrics'] ?? [] as $metric)
            <x-filament::section>
                <x-slot name="heading">{{ $metric['label'] }}</x-slot>

                <p class="text-2xl font-bold">
                    @if ($metric['is_ratio'])
                        {{ $metric['denominator'] === 0 ? '—' : number_format($metric['value'] * 100, 1) . '٪' }}
                    @else
                        {{ number_format($metric['numerator']) }}
                    @endif
                </p>

                @if ($metric['is_ratio'])
                    <p class="fi-section-header-description">
                        {{ number_format($metric['numerator']) }} من {{ number_format($metric['denominator']) }}
                    </p>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">توزيع الطلاب بالمنطقة</x-slot>

        {{-- A region with nobody in it is shown with a zero, never dropped: the
             reader starts from `regions` and joins left. --}}
        <table class="w-full text-start">
            <thead>
                <tr>
                    <th class="text-start">المنطقة</th>
                    <th class="text-start">الطلاب</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['regions'] ?? [] as $region)
                    <tr>
                        <td>{{ $region['name_ar'] }}</td>
                        <td>{{ number_format($region['students']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">الأعلى تقييماً من المدرّسين</x-slot>

            @forelse ($report['top_teachers'] ?? [] as $teacher)
                <p>{{ $teacher['name'] }} — {{ number_format($teacher['average_rating'], 2) }}
                    ({{ $teacher['reviews_count'] }} تقييماً)</p>
            @empty
                <p class="fi-section-header-description">لا مدرّس بلغ الحدّ الأدنى من التقييمات بعد.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">الأعلى نقاطاً من الطلاب</x-slot>

            @forelse ($report['top_students'] ?? [] as $student)
                <p>{{ $student['display_name'] }} — {{ number_format($student['points']) }} نقطة</p>
            @empty
                <p class="fi-section-header-description">لم تُبنَ لوحة هذا الأسبوع بعد.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
