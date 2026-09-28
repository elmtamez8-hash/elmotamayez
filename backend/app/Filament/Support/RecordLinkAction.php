<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Resources\Resource as PanelResource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * زرُّ رأسِ صفحةٍ يفتحُ سجلّاً مرتبطاً — ويختفي لمن لا يفتحُه.
 *
 * ⚠️ `Action::make()` بلا `authorize()` لا يحملُ أيَّ تفويض، وهذا مقبولٌ هنا
 * لسببٍ واحد: الزرُّ **رابطٌ ولا ينفّذُ شيئاً**. السؤالُ كلُّه في
 * {@see RecordLink::to()}، و`visible()` يسألُه قبلَ الرسم.
 */
final class RecordLinkAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  Closure(): ?Model  $related
     */
    public static function make(string $name, string $label, string $resource, Closure $related, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->url(fn (): ?string => RecordLink::to($resource, $related()))
            ->visible(fn (): bool => RecordLink::to($resource, $related()) !== null);
    }
}
