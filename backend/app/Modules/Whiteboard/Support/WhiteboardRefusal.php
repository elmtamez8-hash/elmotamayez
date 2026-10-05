<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A board rule refused the request — answered with the contract's CODE and status
 * (contracts/api.md), not the generic 422 every `DomainException` becomes.
 *
 * The browser turns the code into Arabic (`lib/whiteboard/strings.ts` — one copy of
 * every sentence); the message here is a fallback for any other reader.
 *
 * It renders ITSELF (Laravel asks an exception's own `render()` before the
 * handlers in bootstrap/app.php), so no cross-module handler is registered.
 */
final class WhiteboardRefusal extends DomainException
{
    private const STATUS = [
        'lock_lost' => 409,
        'version_conflict' => 409,
        'pages_changed' => 409,
        'operation_pending' => 409,
        'already_exported' => 409,
        'locked' => 423,
        'replace_forbidden' => 403,
        'library_full' => 409,
    ];

    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $reason, public readonly array $extra = [])
    {
        parent::__construct('تعذّر تنفيذ العملية على السبّورة.');
    }

    public function status(): int
    {
        return self::STATUS[$this->reason] ?? 422;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason, ...$this->extra], $this->status());
    }
}
