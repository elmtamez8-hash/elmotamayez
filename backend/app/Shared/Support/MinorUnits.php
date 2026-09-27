<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Casts\Attribute;
use InvalidArgumentException;

/**
 * المبلغُ بالوحدةِ الكبرى على الشاشة، وبالصغرى في قاعدةِ البيانات.
 *
 * ⛔ قرارُ المالك (٢٠٢٦-٠٩-٢٧): كلُّ مبلغٍ في اللوحةِ يُكتَبُ ويُعرَضُ بالوحدةِ
 * الكبرى — ‏49.99 لا 4999 — ويُخزَّنُ عدداً صحيحاً بالوحدةِ الصغرى كما كان.
 * قبلَه كانَ حقلُ سعرِ الباقةِ رقميّاً حرّاً يُقَصُّ بـ`(int)`، فمَن كتبَ 49.99
 * ظانّاً أنّه يكتبُ ريالاً خزَّنَ 49 هللة.
 *
 * ⚠️ التحويلُ نصّيٌّ، لا `* 100`: ‏`(int) (19.99 * 100)` تساوي 1998 في
 * الفاصلةِ العائمة. نفصلُ عندَ النقطةِ ونُكمِلُ الكسرَ إلى خانتَين.
 *
 * والتحويلُ في النموذج لا في الاستمارة: {@see MinorUnits::attribute()} يبني الخاصّيّةَ
 * الافتراضيّةَ (`price` فوقَ `price_minor`) التي تربطُها الاستمارةُ مباشرة.
 *
 * كلُّ العملاتِ المدعومةِ اليومَ بخانتَين عشريّتَين، كما يقولُ
 * `Settlement\Support\Money` صراحة.
 */
final class MinorUnits
{
    /**
     * ⛔ THE ONE PLACE «two decimals» IS WRITTEN. Every currency in
     * `Payments\Enums\Currency` has two today; a third-decimal currency changes
     * this line, and every model attribute built on {@see MinorUnits::attribute()} follows it.
     */
    private const DECIMALS = 2;

    /** 100 — how many minor units make one major unit. */
    public static function scale(): int
    {
        return 10 ** self::DECIMALS;
    }

    /**
     * A virtual major-unit attribute over a `*_minor` column.
     *
     * ⛔ Owner decision (2026-09-27): the admin types and reads riyals, the
     * column keeps halalas, and the MODEL converts — never the form. So a model
     * declares `protected function price(): Attribute { return
     * MinorUnits::attribute('price_minor'); }` and a form binds `price`.
     *
     * The column stays the stored truth: the API, the Actions, reports and
     * settlement all read `price_minor` and never this. It is NOT appended, so
     * no `toArray()` or API payload grows a key.
     *
     * Reads «49.99» (a string, never a float) or null. Writes accept «49.99»,
     * 49.99 or 49 and store 4999/4900; blank stores null; anything that is not
     * a number with at most two decimals THROWS rather than storing null — a
     * silent null would take a priced plan off sale.
     *
     * `$writable = false` gives a read-only attribute, for a column that only
     * one Action may write (`payment_transactions.refunded_minor`): a setter
     * would be a second door to it that `$fillable` does not guard.
     *
     * @return Attribute<string|null, mixed>
     */
    public static function attribute(string $column, bool $writable = true): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => self::toMajor(
                is_numeric($attributes[$column] ?? null) ? (int) $attributes[$column] : null,
            ),
            set: $writable ? fn (mixed $value): array => [$column => self::fromMajorOrFail($value)] : null,
        );
    }

    /**
     * {@see fromMajor()}, but a value that is filled and still not an amount is
     * an error rather than a null.
     */
    public static function fromMajorOrFail(mixed $major): ?int
    {
        $minor = self::fromMajor($major);

        if ($minor === null && $major !== null && ! (is_string($major) && trim($major) === '')) {
            throw new InvalidArgumentException('Not an amount with at most '.self::DECIMALS.' decimals.');
        }

        return $minor;
    }

    /**
     * «49.99» ⇐ 4999. الفراغُ ⇐ null. ما ليسَ رقماً بخانتَين على الأكثر ⇐ null
     * (التحقّقُ في الحقلِ يرفضُه قبلَ أن يصلَ إلى هنا).
     */
    public static function fromMajor(mixed $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        if (is_int($major)) {
            return $major * self::scale();
        }

        if (is_float($major)) {
            /*
            | ⚠️ A form's `numeric()` hands the setter a FLOAT (Filament's
            | `NumberStateCast`), and `number_format()` ROUNDS: 49.999 would
            | become «50.00» in silence. A float that does not survive the trip
            | to two decimals unchanged is refused like the string «49.999» is.
            */
            $formatted = number_format($major, self::DECIMALS, '.', '');

            if ((float) $formatted !== $major) {
                return null;
            }

            $major = $formatted;
        }

        if (! is_string($major)) {
            return null;
        }

        $major = trim($major);

        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,'.self::DECIMALS.'}))?$/', $major, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = str_pad($m[3] ?? '', self::DECIMALS, '0');
        $minor = (int) ($whole.$fraction);

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** 4999 ⇐ «49.99». ‏null ⇐ null. */
    public static function toMajor(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        $scale = self::scale();
        $absolute = abs($minor);

        return sprintf('%s%d.%0'.self::DECIMALS.'d', $minor < 0 ? '-' : '', intdiv($absolute, $scale), $absolute % $scale);
    }
}
