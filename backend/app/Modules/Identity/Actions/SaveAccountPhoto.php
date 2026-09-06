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
 */
class SaveAccountPhoto extends Action
{
    /** حيثُ تعيشُ الصورُ على قرصِ `public` — تحتَ مجلَّدٍ واحدٍ ليسهلَ تنظيفُه. */
    public const DIRECTORY = 'avatars';

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
                $user->uuid.'-'.Str::random(8).'.'.$file->extension(),
                'public',
            );

            if ($stored === false) {
                throw new DomainException('تعذّر حفظ الصورة. حاول مرة أخرى.');
            }

            $path = $stored;
        }

        $profile->forceFill([$column => $path])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
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
