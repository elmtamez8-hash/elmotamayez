<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\PanelHandoffController;
use Illuminate\Support\Facades\Route;

/*
| ⚠️ **في `web` لا في `api`، وهذا هو بيتُ القصيدِ كلِّه.**
|
| الجسرُ يصنعُ **جلسةً**، والجلسةُ تحتاجُ `StartSession` و`EncryptCookies` —
| وهي في مجموعةِ `web` وحدَها. المسارُ نفسُه تحتَ `api` يُنفَّذُ بلا خطأٍ ظاهرٍ
| ولا يُبقي شيئاً: `Auth::guard('web')->login()` يكتبُ في جلسةٍ لا تُحفَظُ،
| فيعودُ المستخدِمُ إلى شاشةِ الدخولِ نفسِها — عطلٌ صامتٌ يبدو «الجسرُ لا يعمل».
|
| و`Module::registerRoutes()` يحمِّلُ هذا الملفَّ في مجموعةِ `web` من تلقائِه.
|
| ⚠️ **ولا مُحدِّدَ معدَّلٍ هنا**: الصرفُ لا يسكُّ شيئاً ولا يقبلُ مدخَلاً —
| تذكرةٌ عشوائيّةٌ من ٦٤ محرفاً تُخمَّنُ في عمرٍ من ستّينَ ثانية ليست سطحَ هجومٍ
| يُحمى بمحدِّد. والسكُّ نفسُه محمِيٌّ بـ`throttle:auth` حيثُ يقعُ القرار.
*/
Route::get('/panel/enter/{ticket}', [PanelHandoffController::class, 'enter'])
    ->name('panel.handoff.enter');
