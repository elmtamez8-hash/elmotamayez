<?php

declare(strict_types=1);

namespace App\Modules\Community\Contracts;

/**
 * Who, out of a list the CALLER chose, has the product open right now.
 *
 * ⚠️ IT ANSWERS ABOUT THE UUIDS IT IS GIVEN AND NOTHING ELSE. The only caller is
 * `ListOnlineCounterparts`, which passes the other ends of the reader's own
 * private conversations — so no request can learn anything about a person the
 * reader does not already share a thread with.
 */
interface OnlineDirectory
{
    /**
     * @param  list<string>  $userUuids
     * @return list<string> the subset that is online
     */
    public function onlineAmong(array $userUuids): array;
}
