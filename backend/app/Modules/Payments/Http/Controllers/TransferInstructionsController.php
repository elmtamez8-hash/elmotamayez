<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Support\TransferInstructions;
use Illuminate\Http\JsonResponse;

/**
 * إلى أينَ يُحوِّلُ المشتري (بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦).
 *
 * ⚠️ **بمصادقةٍ لا على `‎/platform` العامّ.** ذاك قائمةُ سماحٍ بحقلٍ **واحد**
 * يحرسُها `PublicFieldAllowlist`، وتعليقُه يقولُ إنّ كلَّ حقلٍ يُضافُ إليه
 * ينضمُّ صامتاً إلى عنوانٍ عامّ. ومَن يحتاجُ هذه البياناتِ هو مَن على وشكِ
 * الدفعِ — وهو مُسجَّلُ دخولٍ بالضرورة، إذ لا يُنشِئُ طلباً غيرُه.
 *
 * ⚠️ **ولا ترتبطُ بطلبٍ بعينِه.** الوجهةُ واحدةٌ للمنصّةِ كلِّها، والشاشةُ تعرضُها
 * **قبلَ** أن يُنشَأَ الطلبُ — فربطُها بـ`{order}` يجعلُها غيرَ قابلةٍ للقراءةِ
 * في اللحظةِ الوحيدةِ التي تُقرأُ فيها.
 */
class TransferInstructionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => TransferInstructions::all(),
            // ⚠️ محسوبٌ على الخادمِ لا مُشتَقٌّ في الواجهة: «هل فيها رقمٌ يُحوَّلُ
            // إليه» قاعدةٌ واحدةٌ، وتهجئتُها ثانيةً في TypeScript هي العطبُ الذي
            // يسجّلُه هذا المستودعُ اثنتَي عشرةَ مرّة.
            'configured' => TransferInstructions::areSet(),
        ]);
    }
}
