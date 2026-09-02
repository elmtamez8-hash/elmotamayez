<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Exceptions\PrivateSessionConflictException;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\PendingPrivateRequest;
use App\Shared\Actions\Action;

/**
 * «لم أعد أحتاجه» (FR-024).
 *
 * ⚠️ IT IS A CONFLICT, NOT A REFUSAL. Withdrawing a request the teacher has
 * already accepted is not «you may not» — the student is perfectly entitled to
 * do it and the row has simply moved on, with a lesson now standing behind it.
 * A 403 there sends them to ask for a permission they hold; a 409 tells them to
 * refresh and look at what actually happened.
 *
 * ⚠️ AND IT GOES THROUGH THE SAME CONDITIONAL UPDATE AS EVERY OTHER ENDING. A
 * withdrawal racing an acceptance must lose cleanly — otherwise the student
 * withdraws, the teacher's press succeeds anyway, and an hour is scheduled that
 * nobody asked for.
 */
class WithdrawPrivateSessionRequest extends Action
{
    public function handle(PrivateSessionRequest $request, User $student): PrivateSessionRequest
    {
        if (! PendingPrivateRequest::settle($request, PrivateSessionRequest::WITHDRAWN, $student)) {
            throw new PrivateSessionConflictException('تم البتّ في هذا الطلب بالفعل.');
        }

        return $request->refresh();
    }
}
