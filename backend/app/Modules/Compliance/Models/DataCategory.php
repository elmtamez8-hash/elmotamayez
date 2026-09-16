<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Translatable\HasTranslations;

/**
 * One thing the platform collects — PLATFORM reference data (layer ب).
 *
 * ⚠️ NO `BelongsToWorkspace`, AND IT MUST NOT GAIN ONE. There is one "the
 * student's name" for the product; a tenant column would give every teacher their
 * own privacy policy. The guard is the platform permission
 * `compliance.registry.manage` — see the migration.
 *
 * @property string $key
 * @property string $label
 * @property string $purpose
 * @property string $audience
 * @property bool $is_required
 * @property string $owning_module
 * @property string $table_name
 * @property string $column_name
 * @property int|null $retain_days
 * @property ExpiryBehaviour|null $expiry_behaviour
 * @property ErasureMode $erasure_mode
 */
class DataCategory extends BaseModel
{
    use HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['label', 'purpose'];

    protected $fillable = [
        'key',
        'label',
        'purpose',
        'audience',
        'subject_roles',
        'is_required',
        'owning_module',
        'table_name',
        'column_name',
        'retain_days',
        'expiry_behaviour',
        'erasure_mode',
    ];

    /**
     * كلُّ الأدوارِ — وهو ما يعنيه عمودٌ فارغ.
     *
     * مفرداتُ `PlatformRole`، مكتوبةً نصّاً لأنّ `Compliance` لا تستوردُ من
     * `Identity`: الوحدةُ لا تُسمّي وحدةً أخرى، وثلاثُ كلماتٍ أرخصُ من كسرِ ذلك.
     *
     * @var list<string>
     */
    public const EVERY_SUBJECT = ['student', 'teacher', 'parent'];

    /**
     * عن مَن هذه الفئة — لا مَن يراها.
     *
     * ⚠️ `audience` جوابٌ عن السؤالِ الثاني، وهو نصٌّ حرٌّ بالعربيّةِ يُقرَأُ ولا
     * يُرشَّحُ به («المدرّس المسجَّل عنده · ولي الأمر»). فلم يكنْ في الجدولِ ما
     * يقولُ لمن الفئةُ نفسُها، وشاشةُ «خصوصيّتي» عرضَت الثلاثةَ والثلاثينَ صفّاً
     * لكلِّ حساب.
     *
     * ⚠️ **والفراغُ «لا نعرف ⇒ للجميع»، لا «لا أحد».** العمودُ قابلٌ للفراغِ عن
     * ضرورةٍ في المحرّكَين (انظرِ الهجرة)، وصفٌّ يكتبُه مشغِّلٌ من `/admin` لن
     * يحملَ قيمة. وإخفاءُ فئةٍ بياناتُ صاحبِها فيها شاشةُ موافقةٍ تكذِب، بينما
     * عرضُ فئةٍ لا تخصُّه ضجيجٌ يُقرَأُ ويُتجاوَز.
     *
     * ⚠️ **و`use Illuminate\Database\Eloquent\Casts\Attribute` شرطٌ صامت.**
     * بدونِه يحلُّ PHP الاسمَ على `\Attribute` من نواةِ اللغة، فلا يُطابِقُ
     * نوعُ الإرجاعِ ما يبحثُ عنه Eloquent — **فيتجاهلُ الملحِقَ بلا خطأٍ واحد**
     * ويُعيدُ النصَّ الخامَّ كما هو. قِيسَ: الحمولةُ خرجَت `'["student"]'` نصّاً
     * بدلَ مصفوفة، وستُّ حالاتٍ من سبعٍ بقيَت خضراءَ فوقَها.
     *
     * @return Attribute<list<string>, string>
     */
    protected function subjectRoles(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $decoded = $value === null || $value === '' ? null : json_decode($value, true);

                return is_array($decoded) && $decoded !== []
                    ? array_values(array_map(strval(...), $decoded))
                    : self::EVERY_SUBJECT;
            },
            set: fn (array $value): string => (string) json_encode(array_values($value)),
        );
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'retain_days' => 'integer',
            'expiry_behaviour' => ExpiryBehaviour::class,
            'erasure_mode' => ErasureMode::class,
        ];
    }

    /**
     * Whether this category is swept at all.
     *
     * Both halves are required and neither implies the other: a retention with no
     * behaviour is a duration nothing acts on, and a behaviour with no retention
     * is an action with no trigger. Either alone reads as configured and does
     * nothing.
     */
    public function expires(): bool
    {
        return $this->retain_days !== null && $this->expiry_behaviour !== null;
    }
}
