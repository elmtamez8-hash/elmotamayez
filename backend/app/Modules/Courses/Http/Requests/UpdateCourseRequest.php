<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Support\PromoVideoUrl;
use App\Modules\Tenancy\Support\Permissions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::COURSES_UPDATE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            /*
            | `sometimes` rather than `required`: a PATCH that changes only the
            | price must not be refused for omitting a field it is not touching.
            | It may not be sent EMPTY, though — clearing it would put a course
            | back into the state this rule exists to end.
            */
            'subject' => ['sometimes', 'uuid'],
            'description' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_sequential' => ['nullable', 'boolean'],
            /*
            | The private session's length (023 · FR-016أ). Bounded because the
            | column is an `unsignedSmallInteger`: SQLite stores any integer in
            | one and MySQL in strict mode rejects it, so a value only the
            | production database refuses is a value no local test can see.
            */
            'private_session_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            /*
            | The promotional video's link (018 · FR-007). Refused HERE, at the
            | door — a link accepted now and judged at display time is a row
            | sitting in the database waiting to be embedded.
            |
            | ⚠️ THE RULE CALLS `PromoVideoUrl`, IT DOES NOT RESTATE IT. A second
            | pattern here and in the action is the two-spellings defect, and its
            | failure direction is a string reaching an `iframe src` through
            | whichever door was not updated. The action checks again anyway,
            | because Filament and the seeders arrive with no form behind them.
            |
            | `null` is how a teacher removes the video.
            */
            'promo_video_url' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && ! PromoVideoUrl::accepts($value)) {
                    $fail('الرابط غير مقبول. الصق رابط فيديو من يوتيوب، مثل https://youtu.be/… أو https://www.youtube.com/watch?v=…');
                }
            }],
        ];
    }
}
