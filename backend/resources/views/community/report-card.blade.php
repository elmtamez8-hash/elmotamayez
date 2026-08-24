{{--
    The printable cumulative report card (spec 010 · FR-039, FR-041, FR-051).

    Inline styles on purpose: mPDF parses a narrow subset of CSS and has no
    build step behind it, so a stylesheet here would be a second place to
    maintain with no tooling to tell anyone when it drifts.
--}}
<html lang="ar" dir="rtl">
<body style="font-family: cairo; font-size: 11pt; color: #1f2937;">

@php
    /*
     * ⚠️ ARABIC-INDIC DIGITS, AND `·` IS BANNED AS A SEPARATOR HERE. Both were
     * found by rendering the document and looking at it, which is the only way
     * either could be found: Cairo draws U+00B7 as a glyph indistinguishable from
     * ٠ at body size, so «٨٥٪ · الحضور» reads as «٨٥٪ ٠ الحضور» — a stray zero
     * between two grades on a document a parent reads. An em dash is unambiguous.
     *
     * The digits are the frontend's `lib/numerals.ts` rule reaching the one
     * surface that has no browser to apply it: a page that mixes ٨٥ and 92 tells
     * the reader the two numbers came from different places.
     */
    $ar = fn (string $text): string => strtr($text, [
        '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
        '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        // ⚠️ THE DECIMAL MARK TOO. `number_format` returns a Latin `.`, and the
        // whole product writes «٧٦٫٧» — the frontend's `arabicDecimal()` gets
        // this for free from `toLocaleString('ar-QA')` and this surface, having
        // no browser, has to say it. A Latin point between Arabic-Indic digits
        // is the same mixed-numeral tell `lib/numerals.ts` exists to close.
        '.' => '٫',
    ]);

    /*
     * ⚠️ AN ARABIC LONG DATE, NEVER `Y-m-d`, AND BIDI IS THE REASON. The first
     * render of this page printed «من ٢٠٢٦-٠٨-٠١ إلى ٣١-٠٨-٢٠٢٦» — the two dates
     * of one period in OPPOSITE component order, because a hyphen is a neutral
     * character and the bidi algorithm reorders a numeric run around it
     * differently depending on what sits either side. Nothing is malformed and
     * nothing errors; the reader simply cannot tell which number is the day.
     *
     * `IntlDateFormatter` is what knows `ar-QA` numbers in Arabic-Indic —
     * `Carbon::isoFormat('ar_QA')` translates the month name and leaves the
     * digits Latin, which is the same measurement 013 made and wrote down.
     */
    $arDate = function (\Carbon\CarbonInterface $date): string {
        $formatter = new \IntlDateFormatter(
            'ar-QA',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
        );

        return (string) $formatter->format($date);
    };
@endphp

<h1 style="text-align: center; font-size: 18pt; margin-bottom: 2mm;">كشف التقديرات</h1>

<p style="text-align: center; font-size: 10pt; color: #6b7280; margin-top: 0;">
    {{ $card->student?->name }}
    &nbsp;—&nbsp;
    من {{ $arDate($card->period_start) }} إلى {{ $arDate($card->period_end) }}
</p>

<table width="100%" cellpadding="6" style="border: 0.3mm solid #e5e7eb; margin-bottom: 6mm;">
    <tr>
        <td style="text-align: center;">
            <div style="font-size: 9pt; color: #6b7280;">التقدير العام</div>
            <div style="font-size: 20pt; font-weight: bold;">
                {{ $card->overall_pct === null ? '—' : $ar(number_format($card->overall_pct, 1)).'٪' }}
            </div>
        </td>
        <td style="text-align: center;">
            <div style="font-size: 9pt; color: #6b7280;">مؤشّر التحسّن</div>
            <div style="font-size: 20pt; font-weight: bold;">
                {{-- A dash, never a zero: no teacher rated improvement is not
                     the same statement as "did not improve" (FR-053). --}}
                {{ $card->improvement_index === null ? '—' : $ar(number_format($card->improvement_index, 1)).'٪' }}
            </div>
        </td>
    </tr>
</table>

{{-- FR-041: each teacher's contribution shown separately, never merged. --}}
@foreach ($card->segments as $segment)
    <h2 style="font-size: 13pt; margin-bottom: 1mm;">{{ $segment->teacher?->name }}</h2>

    <table width="100%" cellpadding="5" style="border-collapse: collapse; margin-bottom: 5mm;">
        <thead>
            <tr style="background-color: #f3f4f6;">
                <th align="right" style="border-bottom: 0.2mm solid #e5e7eb;">المكوّن</th>
                <th align="center" style="border-bottom: 0.2mm solid #e5e7eb;">النتيجة</th>
                <th align="center" style="border-bottom: 0.2mm solid #e5e7eb;">الوزن</th>
            </tr>
        </thead>
        <tbody>
            @php($labels = [
                'exams' => 'الاختبارات',
                'homework' => 'الواجبات',
                'attendance' => 'الحضور',
                'participation' => 'المشاركة',
            ])
            @forelse ($segment->components as $key => $row)
                <tr>
                    <td style="border-bottom: 0.2mm solid #f3f4f6;">{{ $labels[$key] ?? $key }}</td>
                    <td align="center" style="border-bottom: 0.2mm solid #f3f4f6;">
                        {{ $ar(number_format((float) $row['pct'], 1)) }}٪
                    </td>
                    {{-- The weight AS RE-WEIGHTED, so the three shown here add up
                         to 100 and the reader can check the arithmetic that
                         produced the grade beside them. --}}
                    <td align="center" style="border-bottom: 0.2mm solid #f3f4f6;">
                        {{ $ar(number_format((float) $row['weight'], 0)) }}٪
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" style="color: #6b7280;">لا توجد درجات مسجّلة في هذه الفترة.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td style="font-weight: bold;">تقدير هذا المدرّس</td>
                <td align="center" colspan="2" style="font-weight: bold;">
                    {{ $segment->segment_pct === null ? '—' : $ar(number_format($segment->segment_pct, 1)).'٪' }}
                </td>
            </tr>
        </tfoot>
    </table>
@endforeach

<p style="font-size: 8pt; color: #9ca3af; margin-top: 8mm;">
    مكوّن بلا بيانات في الفترة يُستبعَد من الحساب وتُعاد موازنة الباقي — ولا يُحتسب صفراً.
</p>

</body>
</html>
