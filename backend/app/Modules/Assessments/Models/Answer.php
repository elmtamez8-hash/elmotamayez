<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends BaseModel
{
    use BelongsToWorkspace;

    protected $table = 'exam_answers';

    protected $fillable = [
        'workspace_id',
        'attempt_id',
        'question_id',
        'selected_option_ids',
        'is_correct',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
            'points' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
