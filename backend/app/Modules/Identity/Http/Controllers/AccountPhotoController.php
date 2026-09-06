<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\SaveAccountPhoto;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * صورةُ الحساب — لكلِّ من يملكُ ملفَّ مدرّسٍ أو ملفَّ طالب.
 *
 * ⚠️ بابٌ واحدٌ للدَّورَينِ لا بابانِ. السؤالُ «ما صورةُ هذا الحساب؟» واحدٌ،
 * والعمودُ الذي يجيبُه يختلفُ باختلافِ الملفِّ لا باختلافِ السؤال؛ وبابانِ يعنيانِ
 * شاشتَينِ ثمّ اختلافاً في الحدِّ الأقصى أو في الصيغِ المقبولةِ لا يلاحظُه أحد.
 */
class AccountPhotoController extends Controller
{
    /**
     * ⚠️ الحدُّ والصيغُ هنا لا في `platform_settings`: هذه ليستْ رقماً تشغيليّاً
     * يضبطُه مشغِّلٌ كحدِّ الفيديو، بل سقفٌ لصورةِ وجهٍ في دائرةٍ قطرُها ٩٦ بكسل.
     */
    public function store(Request $request, SaveAccountPhoto $action): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'photo.required' => 'اختر صورة.',
            'photo.image' => 'الملف ليس صورة.',
            'photo.mimes' => 'الصيغ المقبولة: JPG أو PNG أو WEBP.',
            'photo.max' => 'الصورة أكبر من ٤ ميغابايت.',
        ]);

        try {
            $path = $action->handle($this->currentUser($request), $request->file('photo'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['photo_url' => $path === null ? null : asset('storage/'.$path)]);
    }

    /** إزالةُ الصورة — والملفُّ يُحذَفُ من القرص، لا يُترَكُ يتيماً. */
    public function destroy(Request $request, SaveAccountPhoto $action): JsonResponse
    {
        try {
            $action->handle($this->currentUser($request), null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['photo_url' => null]);
    }
}
