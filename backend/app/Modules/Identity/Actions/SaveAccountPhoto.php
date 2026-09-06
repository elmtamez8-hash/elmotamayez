<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

/**
 * صورةُ الحسابِ — وضعاً وحذفاً.
 *
 * ⚠️ العمودانِ كانا بأربعةِ قرّاءٍ وبلا كاتبٍ واحد. `teacher_profiles.photo_path`
 * و`student_profiles.avatar_path` يُقرآنِ في كشفِ الحضورِ وفي قائمةِ المجموعةِ وفي
 * الصفحةِ الأولى وفي بطاقةِ الكورس — ولا سطرَ في الشجرةِ كلِّها يكتبُ أحدَهما. فما
 * يبدو احتياطاً (الحرفُ الأوّلُ في دائرة) كانَ الحالةَ الوحيدةَ التي يقدرُ عليها
 * المنتَج، وهي عائلةُ «قيمةٌ لها قرّاءٌ ولا كاتبَ لها تُقرأُ كأنّها مُنفَّذة».
 *
 * ⚠️ والقديمُ يُحذَفُ فعلاً، خلافاً للإيصال. إيصالُ الدفعِ يُحتفَظُ به لأنّه سجلُّ
 * ما اعتمدَه الموظّفُ أو رفضَه؛ أمّا صورةُ الحسابِ فلا تُقرَّرُ عليها قضيّةٌ ولا
 * يُراجعُها أحد، فتركُ القديمةِ على القرصِ تخزينٌ لا يشيرُ إليه شيءٌ أبداً — وصورةُ
 * وجهِ طفلٍ باقيةٌ بعدَ أن طلبَ صاحبُها إزالتَها.
 *
 * ⚠️ والحذفُ بعدَ الحفظِ لا قبلَه: لو حُذِفَ القديمُ أوّلاً ثمّ فشلَ الكتابةُ،
 * لبقيَ الحسابُ بلا صورةٍ وبعمودٍ يشيرُ إلى ملفٍّ لم يعدْ موجوداً.
 *
 * ⚠️ وكلُّ صورةٍ تُعادُ ترميزُها مربّعةً ٥١٢×٥١٢ JPEG هنا، **لا في المتصفّحِ
 * وحدَه**. المقصُّ في الواجهةِ هو التجربة؛ وهذا هو الحدّ. وثلاثةُ مكاسبَ لا
 * يعطيها المقصُّ لأنّه على الجانبِ الذي لا يُوثَقُ به:
 *
 *   ١. **EXIF يُمحى، وفيه إحداثيّاتُ GPS.** صورةُ هاتفٍ تحملُ موضعَ التقاطِها،
 *      وصورُ الطلّابِ تصلُ صفحاتٍ عامّةً عبرَ بطاقاتِ المراجعاتِ في
 *      {@see ShowPublicTeacher} — وعلى هذه المنصّةِ قاصرون. الترميزُ الثاني
 *      يُسقِطُ الوسمَ كلَّه بلا سطرٍ يُدارُ به.
 *   ٢. **الملفُّ المزدوجُ (polyglot) يموت.** قرصُ `public` يُخدَمُ مباشرةً من
 *      `/storage`، وملفٌّ صحيحُ الترويسةِ يحملُ حمولةً في ذيلِه يمرُّ من
 *      `mimes:` — ولا ينجو من إعادةِ ترميزٍ تقرأُ البكسلَ وتكتبُه من جديد.
 *   ٣. **الحجمُ يصيرُ حدّاً لا رجاءً.** `max:4096` هو ما يُقبَلُ دخولاً؛ وهذا هو
 *      ما يُخزَّنُ فعلاً — نحوَ خمسينَ كيلوبايت مهما أُرسِلَ.
 *
 * ⚠️ والسائقُ `Gd` صراحةً: هذا الخادمُ بلا `imagick` (مقيسٌ ٢٠٢٦-٠٩-٠٦)، والحزمةُ
 * تختارُ سائقَها الافتراضيَّ بنفسِها — فاعتمادٌ على الافتراضِ يعملُ على جهازٍ
 * ويرمي على آخرَ بجملةٍ لا تُنسَبُ إلى صورةِ حساب. ولا `optimize()`: يستدعي
 * برامجَ خارجيّةً (`jpegoptim` وإخوتُها) ليست على كلِّ خادم، وغيابُها إمّا صمتٌ
 * أو رمي — وهي تكسبُ كيلوبايتاتٍ من ملفٍّ صارَ خمسينَ.
 */
class SaveAccountPhoto extends Action
{
    /** حيثُ تعيشُ الصورُ على قرصِ `public` — تحتَ مجلَّدٍ واحدٍ ليسهلَ تنظيفُه. */
    public const DIRECTORY = 'avatars';

    /** ضلعُ المربَّعِ المخزَّن. الدائرةُ تُرسَمُ بالـCSS فوقَه في كلِّ شاشة. */
    public const SIZE = 512;

    /** ٨٥ لصورةِ وجهٍ صغيرة: الفرقُ عن ٩٥ لا يُرى، والملفُّ نصفُ الحجم. */
    private const QUALITY = 85;

    /**
     * @return string|null مسارُ الصورةِ الجديدة، أو null بعدَ الإزالة
     */
    public function handle(User $user, ?UploadedFile $file): ?string
    {
        [$profile, $column] = $this->targetOf($user);

        $previous = $profile->getAttribute($column);

        $path = null;

        if ($file !== null) {
            $stored = $file->storeAs(
                self::DIRECTORY,
                // اسمٌ عشوائيٌّ بجانبِ الـuuid: بلا العشوائيِّ يحملُ الاسمُ الجديدُ
                // مسارَ القديمِ نفسَه، فتخدمُ ذاكرةُ المتصفّحِ والـCDN الصورةَ
                // السابقةَ بعدَ التغيير — تغييرٌ يبدو أنّه لم يحدث.
                //
                // ⚠️ والامتدادُ `jpg` دائماً لا `$file->extension()`: ما يُخزَّنُ
                // بعدَ سطرٍ من هنا JPEG مهما وصلَ، واسمٌ ينتهي بـ`.png` فوقَ
                // بايتاتِ JPEG هو النوعُ الذي يخمّنُه كلُّ خادمٍ ثابتٍ من الاسم.
                $user->uuid.'-'.Str::random(8).'.jpg',
                'public',
            );

            if ($stored === false) {
                throw new DomainException('تعذّر حفظ الصورة. حاول مرة أخرى.');
            }

            $this->normalise(Storage::disk('public')->path($stored));

            $path = $stored;
        }

        $profile->forceFill([$column => $path])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }

    /**
     * مربّعٌ ٥١٢×٥١٢ من JPEG، فوقَ الملفِّ نفسِه.
     *
     * `Fit::Crop` لا `Fit::Contain`: الإطارُ دائريٌّ في كلِّ شاشةٍ تعرضُ هذه
     * الصورة، و«احتواءٌ» يعني أشرطةً تُقَصُّ بعدَها على أيِّ حال — بيدِ المتصفّحِ
     * هذه المرّةَ ومن المنتصفِ لا من حيثُ وضعَ صاحبُها وجهَه.
     *
     * وصورةٌ أصغرَ من ٥١٢ تُكبَّرُ إلى ٥١٢: مقاسٌ واحدٌ يعني أنّ كلَّ قارئٍ
     * يحسبُ الدائرةَ نفسَها، وتكلفةُ التكبيرِ بضعةُ كيلوبايتاتٍ مرّةً واحدة.
     */
    private function normalise(string $absolutePath): void
    {
        Image::useImageDriver(ImageDriver::Gd)
            ->loadFile($absolutePath)
            ->fit(Fit::Crop, self::SIZE, self::SIZE)
            ->quality(self::QUALITY)
            ->save($absolutePath);
    }

    /**
     * أيُّ ملفٍّ يحملُ صورةَ هذا الحساب.
     *
     * ⚠️ ملفُّ المدرّسِ أوّلاً، ثمّ ملفُّ الطالب. حسابٌ بلا أيِّهما — وليُّ أمرٍ أو
     * موظّفٌ — يُرفَضُ بجملةٍ لا بصمت: عمودٌ ثالثٌ يُخترَعُ له هنا يعني صورةً لا
     * يقرؤها أيُّ شاشةٍ في المنتَج.
     *
     * @return array{0: Model, 1: string}
     */
    private function targetOf(User $user): array
    {
        if ($user->teacherProfile !== null) {
            return [$user->teacherProfile, 'photo_path'];
        }

        if ($user->studentProfile !== null) {
            return [$user->studentProfile, 'avatar_path'];
        }

        throw new DomainException('لا يوجد ملف شخصي لهذا الحساب يحمل صورة.');
    }
}
