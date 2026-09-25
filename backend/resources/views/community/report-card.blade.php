{{--
    The printable cumulative report card (spec 010 · FR-039, FR-041, FR-051).

    Inline styles and TABLES for layout, on purpose: mPDF parses a narrow subset
    of CSS (no flex, no grid, partial border-radius) and has no build step, so a
    stylesheet would be a second place to maintain with nothing to tell anyone
    when it drifts. The colours are the platform's `@theme` tokens from
    `frontend/src/app/globals.css`, written out — primary #8a1538, accent #956d2f,
    ink #2a2224, muted #6e625e, surface #faf6f0, line #e8dfd4, primary-soft #f7ebef.
--}}
<html lang="ar" dir="rtl">
<body style="font-family: cairo; font-size: 10.5pt; color: #2a2224;">

@php
    /*
     * ⚠️ ARABIC-INDIC DIGITS, AND `·` IS BANNED AS A SEPARATOR HERE. Cairo draws
     * U+00B7 as a glyph indistinguishable from ٠ at body size, so «٨٥٪ · الحضور»
     * reads as «٨٥٪ ٠ الحضور». An em dash is unambiguous. The digits are the
     * frontend's `lib/numerals.ts` rule reaching the one surface with no browser.
     */
    $ar = fn (string $text): string => strtr($text, [
        '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
        '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        // The decimal mark too: the product writes «٧٦٫٧», never «76.7».
        '.' => '٫',
    ]);

    $pct = fn (?float $value, int $decimals = 1): string => $value === null
        ? '—'
        : $ar(number_format($value, $decimals)).'٪';

    /*
     * ⚠️ AN ARABIC LONG DATE BUILT BY HAND, NOT `IntlDateFormatter`. The container's
     * ICU carries no Arabic calendar data, so `ar-QA` silently fell back to English
     * and printed «September 1, 2026» on an Arabic document (measured on production,
     * 2026-09-25). Month names are the ones `toLocaleString('ar-QA')` gives the
     * frontend, so the page and the PDF say the same thing.
     */
    $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    $arDate = fn (\Carbon\CarbonInterface $date): string => $ar((string) $date->day).' '.$months[$date->month - 1].' '.$ar((string) $date->year);

    $issued = ($card->published_at ?? now())->copy()->timezone('Asia/Qatar');

    $labels = [
        'exams' => 'الاختبارات',
        'homework' => 'الواجبات',
        'attendance' => 'الحضور',
        'participation' => 'المشاركة',
    ];
@endphp

<htmlpagefooter name="page-footer">
    <table width="100%" style="border-top: 0.3mm solid #e8dfd4; font-size: 8pt; color: #6e625e;">
        <tr>
            <td style="padding-top: 2mm;">{{ $platformName }} — كشف التقديرات التراكمي</td>
            <td style="padding-top: 2mm; text-align: left;">صفحة {PAGENO} من {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="page-footer" value="on" />

{{-- ── Header: logo, platform, document title ─────────────────────────── --}}
<table width="100%" cellpadding="0" style="margin-bottom: 3mm;">
    <tr>
        <td width="20mm" style="vertical-align: middle;">
            <img src="{{ $logoPath }}" width="17mm" height="17mm" />
        </td>
        <td style="vertical-align: middle; padding-right: 3mm;">
            <div style="font-size: 17pt; font-weight: bold; color: #8a1538;">{{ $platformName }}</div>
            <div style="font-size: 9pt; color: #6e625e;">{{ parse_url((string) config('cms.site_url'), PHP_URL_HOST) }}</div>
        </td>
        <td style="vertical-align: middle; text-align: left;">
            <div style="font-size: 15pt; font-weight: bold; color: #2a2224;">كشف التقديرات</div>
            <div style="font-size: 9pt; color: #956d2f;">التراكمي</div>
        </td>
    </tr>
</table>

<div style="height: 0.9mm; background-color: #8a1538;"></div>
<div style="height: 0.4mm; background-color: #956d2f; margin-bottom: 5mm;"></div>

{{-- ── Who and when ──────────────────────────────────────────────────── --}}
<table width="100%" cellpadding="7" style="background-color: #faf6f0; border: 0.3mm solid #e8dfd4; margin-bottom: 5mm;">
    <tr>
        <td width="40%">
            <div style="font-size: 8.5pt; color: #6e625e;">الطالب</div>
            <div style="font-size: 12pt; font-weight: bold;">{{ $card->student?->name }}</div>
        </td>
        <td width="35%">
            <div style="font-size: 8.5pt; color: #6e625e;">الفترة</div>
            <div style="font-size: 10.5pt;">{{ $arDate($card->period_start) }} — {{ $arDate($card->period_end) }}</div>
        </td>
        <td width="25%">
            <div style="font-size: 8.5pt; color: #6e625e;">تاريخ الإصدار</div>
            <div style="font-size: 10.5pt;">{{ $arDate($issued) }}</div>
        </td>
    </tr>
</table>

{{-- ── The two headline numbers ─────────────────────────────────────── --}}
<table width="100%" cellpadding="0" style="margin-bottom: 7mm;">
    <tr>
        <td width="49%" style="background-color: #8a1538; color: #ffffff; text-align: center; padding: 5mm 3mm;">
            <div style="font-size: 9.5pt;">التقدير العام</div>
            <div style="font-size: 26pt; font-weight: bold;">{{ $pct($card->overall_pct) }}</div>
        </td>
        <td width="2%"></td>
        <td width="49%" style="background-color: #f7ebef; color: #8a1538; text-align: center; padding: 5mm 3mm; border: 0.3mm solid #e8dfd4;">
            <div style="font-size: 9.5pt;">مؤشّر التحسّن</div>
            {{-- A dash, never a zero: no teacher rated improvement is not the
                 same statement as "did not improve" (FR-053). --}}
            <div style="font-size: 26pt; font-weight: bold;">{{ $pct($card->improvement_index) }}</div>
        </td>
    </tr>
</table>

{{-- FR-041: each teacher's contribution shown separately, never merged. --}}
@forelse ($card->segments as $segment)
    <table width="100%" cellpadding="0" style="margin-bottom: 2mm;" autosize="1">
        <tr>
            <td style="border-right: 1.2mm solid #956d2f; padding-right: 3mm;">
                <div style="font-size: 8.5pt; color: #6e625e;">المدرّس</div>
                <div style="font-size: 12.5pt; font-weight: bold;">{{ $segment->teacher?->name ?? 'مدرّس' }}</div>
            </td>
            <td style="text-align: left; vertical-align: bottom;">
                <span style="font-size: 9pt; color: #6e625e;">تقدير هذا المدرّس&nbsp;&nbsp;</span>
                <span style="font-size: 14pt; font-weight: bold; color: #8a1538;">{{ $pct($segment->segment_pct) }}</span>
            </td>
        </tr>
    </table>

    <table width="100%" cellpadding="6" style="border-collapse: collapse; margin-bottom: 7mm; border: 0.3mm solid #e8dfd4;">
        <thead>
            <tr style="background-color: #f7ebef;">
                <th align="right" style="color: #8a1538; font-weight: bold; border-bottom: 0.3mm solid #e8dfd4;">المكوّن</th>
                <th align="center" width="25%" style="color: #8a1538; font-weight: bold; border-bottom: 0.3mm solid #e8dfd4;">النتيجة</th>
                <th align="center" width="25%" style="color: #8a1538; font-weight: bold; border-bottom: 0.3mm solid #e8dfd4;">الوزن</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($segment->components as $key => $row)
                <tr style="background-color: {{ $loop->even ? '#faf6f0' : '#ffffff' }};">
                    <td style="border-bottom: 0.2mm solid #e8dfd4;">{{ $labels[$key] ?? $key }}</td>
                    <td align="center" style="border-bottom: 0.2mm solid #e8dfd4; font-weight: bold;">
                        {{ $pct((float) $row['pct']) }}
                    </td>
                    {{-- The weight AS RE-WEIGHTED, so the rows add up to 100 and
                         the reader can check the arithmetic behind the grade. --}}
                    <td align="center" style="border-bottom: 0.2mm solid #e8dfd4; color: #6e625e;">
                        {{ $pct((float) $row['weight'], 0) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" style="color: #6e625e;">لا توجد درجات مسجّلة في هذه الفترة.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@empty
    <p style="color: #6e625e; text-align: center;">لا توجد درجات في هذه الفترة.</p>
@endforelse

<table width="100%" cellpadding="6" style="margin-top: 4mm;">
    <tr>
        <td style="border-right: 0.8mm solid #956d2f; background-color: #faf6f0; font-size: 8.5pt; color: #6e625e;">
            مكوّن بلا بيانات في الفترة يُستبعَد من الحساب وتُعاد موازنة الباقي — ولا يُحتسب صفراً.
        </td>
    </tr>
</table>

</body>
</html>
