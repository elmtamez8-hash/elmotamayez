<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «هذا العنصرُ لهذه المجموعة» — صفٌّ واحدٌ لكلِّ (عنصر، مجموعة).
 *
 * **وغيابُ كلِّ صفٍّ هو «للجميع»**، فلا حالةَ ثالثةَ تُقرَأُ ولا قيمةَ مبدئيّةَ
 * تُكتَب.
 *
 * ⚠️ **ويُكتَبُ من `SaveLessonAudience` وحدَها**، فهي التي تتحقّقُ أنّ المجموعةَ
 * من كورسِ الدرسِ نفسِه.
 *
 * ⛔ **ويُقرَأُ على مسارِ الطالبِ بـ`DB::table` لا من هنا.** `BelongsToWorkspace`
 * يضيفُ `WorkspaceScope`، و`WorkspaceContext::id()` يرجعُ إلى
 * `users.last_workspace_id` — وهو **مطبوعٌ على كلِّ طالبٍ أُضيفَ يوماً إلى
 * مساحةِ عمل** (ستّةُ صفوفٍ مقيسةٌ على قاعدةٍ حقيقيّة). فالقراءةُ المُنطَقةُ
 * تُرجِعُ صفوفاً أقلَّ لطالبٍ مختومٍ بمساحةٍ أخرى: مقامٌ يختلفُ باختلافِ
 * القارئِ، وهو نقضُ FR-013أ بالآلةِ الموضوعةِ لتحقيقِها — ولا تجهيزةَ بمساحةٍ
 * واحدةٍ تراه. والصنفُ يبقى للكتابةِ وللوحةِ الإدارة، حيثُ النطاقُ هو الحارسُ
 * المقصود.
 *
 * @property int $lesson_id
 * @property int $cohort_id
 * @property int $workspace_id
 */
class LessonCohortScope extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'lesson_id',
        'cohort_id',
    ];

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
