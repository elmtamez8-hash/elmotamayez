<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.channels' => ['present', 'array'],
            'preferences.*.channels.*' => ['string', Rule::in($this->implementedChannels())],
            'preferences.*.digest_window_minutes' => ['nullable', 'integer', 'between:5,1440'],
        ];
    }

    /**
     * A mandatory type cannot be silenced (FR-029). Checked here as well as in the
     * Action so the user gets a field-level message naming the type, rather than a
     * generic domain error — but the Action is what actually enforces it, since
     * Filament and seeders never pass through this class.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $preferences */
            $preferences = $this->input('preferences', []);

            foreach ($preferences as $index => $preference) {
                $type = NotificationType::tryFrom((string) ($preference['type'] ?? ''));

                if ($type === null || ! $type->isMandatory()) {
                    continue;
                }

                if (($preference['channels'] ?? []) === []) {
                    $validator->errors()->add(
                        "preferences.{$index}.channels",
                        "«{$type->label()}» إشعار إلزامي ولا يمكن إيقافه.",
                    );
                }
            }
        });
    }

    /**
     * Only channels that actually exist (FR-030). An unimplemented one is not
     * merely discouraged in the UI — it is rejected, because storing it would
     * promise delivery on something with no code behind it.
     *
     * @return list<string>
     */
    private function implementedChannels(): array
    {
        return array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            app(ChannelRegistry::class)->implemented(),
        );
    }
}
