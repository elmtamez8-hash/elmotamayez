<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * Where a request has got to.
 *
 * ⚠️ `Processing` IS THE STATE THAT STRANDS, and it is the reason
 * `RetryStalledDataRequestsJob` exists. The job is dispatched once with
 * `tries: 1`, so a worker killed after this value is written leaves the request
 * here for ever while `due_at` — a legal deadline — passes with nobody told. The
 * shipped precedent is `recording_status = 'ingesting'`, exactly: a state written
 * before a killable call, and a sweep that asked about a different value.
 */
enum DataRequestStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Refused = 'refused';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار التنفيذ',
            self::Processing => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Refused => 'مرفوض',
            self::OnHold => 'موقوف بتعليقٍ قانونيّ',
        };
    }

    /** Whether the request still occupies its subject's one open slot. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::OnHold => true,
            self::Completed, self::Refused => false,
        };
    }
}
