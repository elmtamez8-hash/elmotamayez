<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\CourseResource;
use Filament\Resources\Resource as PanelResource;
use Illuminate\Database\Eloquent\Model;

/**
 * رابطٌ من شاشةٍ إلى سجلٍّ في شاشةٍ أخرى — أو لا رابطَ أبداً.
 *
 * ⚠️ **الرابطُ لا يُرسَمُ إلّا لمن يفتحُ الهدف.** قارئُ `/admin` ثلاثة: مديرُ
 * المنصّة ومسؤولُ الماليّة ومسؤولُ الامتثال، وأغلبُ القوائمِ لمديرِ المنصّةِ
 * وحدَه. عمودُ «الكورس» على شاشةِ الطلباتِ يقرؤه مسؤولُ الماليّة، وشاشةُ
 * الكورسِ مغلقةٌ عليه — فرابطٌ هناك يقودُه إلى ٤٠٣، أو أسوأ: إلى صفحةٍ يفتحُها
 * سياسةٌ بلا الدالّةِ المطلوبة، وFilament v5 يقرأُ الدالّةَ الغائبةَ «مسموح»
 * (`docs/gotchas/tenancy.md`). فالسؤالُ مرّتان: بابُ الشاشةِ (`canAccess()`)
 * ثمّ بابُ السجلِّ (`canView()` أو `canEdit()`).
 *
 * ⚠️ **ولا رابطَ إلى سجلٍّ محذوفٍ حذفاً ليّناً.** `Order::course()` و
 * `Enrollment::course()` تحملان `withTrashed()` عمداً — الإيصالُ يُسمّي ما دُفِعَ
 * ثمنُه — بينما استعلامُ {@see CourseResource} لا يرى
 * المحذوف، فالرابطُ كانَ سيفتحُ ٤٠٤.
 *
 * والعنوانُ من `Resource::getUrl()` وحدَه، لا مسارٌ يُكتَبُ باليد: اسمُ المسارِ
 * يتغيّرُ مع الـslug، وعنوانٌ مكتوبٌ يبقى يشيرُ إلى الاسمِ القديم.
 */
final class RecordLink
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function to(string $resource, ?Model $record): ?string
    {
        if ($record === null || ! $record->exists) {
            return null;
        }

        if (method_exists($record, 'trashed') && $record->trashed()) {
            return null;
        }

        if (! $resource::canAccess()) {
            return null;
        }

        if ($resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        if ($resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        return null;
    }
}
