<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Actions\UpdateNotificationPreferences;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\UpdatePreferencesData;
use App\Modules\Notifications\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Notifications\Http\Requests\UpdateQuietHoursRequest;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NotificationPreferenceController extends Controller
{
    /**
     * Metadata for the settings screen: every type, and only the channels that
     * exist. An unimplemented channel is absent rather than disabled — offering a
     * greyed-out WhatsApp toggle promises a date nobody has committed to.
     */
    public function types(ChannelRegistry $registry): JsonResponse
    {
        return response()->json([
            'channels' => array_map(
                static fn (NotificationChannel $channel): array => [
                    'key' => $channel->value,
                    'label' => $channel->label(),
                ],
                $registry->implemented(),
            ),
            'types' => array_map(
                static fn (NotificationType $type): array => [
                    'key' => $type->value,
                    'label' => $type->label(),
                    'default_channels' => array_map(
                        static fn (NotificationChannel $channel): string => $channel->value,
                        $type->defaultChannels(),
                    ),
                    'is_mandatory' => $type->isMandatory(),
                    'targets_guardians' => $type->targetsGuardians(),
                ],
                NotificationType::cases(),
            ),
        ]);
    }

    public function index(Request $request, UpdateNotificationPreferences $action): JsonResponse
    {
        return response()->json([
            'preferences' => $this->present($action->all($this->currentUser($request))),
        ]);
    }

    public function update(UpdatePreferencesRequest $request, UpdateNotificationPreferences $action): JsonResponse
    {
        $preferences = $action->handle(
            $this->currentUser($request),
            UpdatePreferencesData::fromArray($request->validated()),
        );

        return response()->json(['preferences' => $this->present($preferences)]);
    }

    public function updateQuietHours(UpdateQuietHoursRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $user->forceFill([
            'quiet_hours_start' => $validated['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $validated['quiet_hours_end'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ])->save();

        return response()->json([
            'quiet_hours_start' => $user->quiet_hours_start,
            'quiet_hours_end' => $user->quiet_hours_end,
            'timezone' => $user->timezone,
        ]);
    }

    /**
     * @param  Collection<int, NotificationPreference>  $preferences
     * @return list<array<string, mixed>>
     */
    private function present(Collection $preferences): array
    {
        return array_values($preferences->map(static fn (NotificationPreference $preference): array => [
            'type' => $preference->type,
            'channels' => $preference->channels,
            'digest_window_minutes' => $preference->digest_window_minutes,
        ])->all());
    }
}
