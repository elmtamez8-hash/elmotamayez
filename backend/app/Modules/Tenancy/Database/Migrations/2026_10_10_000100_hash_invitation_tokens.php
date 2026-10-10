<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security scan 2026-10-10, F21 — every stored invitation token becomes its
 * SHA-256, the form `Invitation::hashToken()` looks up. A pending invitation's
 * link keeps working: the inviter still holds the plain value, and the lookup
 * hashes it.
 *
 * ⚠️ `chunkById`, never `chunk`: the walk rewrites the column it would page on
 * otherwise (database.md). A 64-character hex value is already a hash, so a
 * second run leaves it alone — a token generated before this was never hex.
 *
 * No `down()`: a hash cannot be turned back into the credential, which is the
 * point.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invitations')->select(['id', 'token'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $token = (string) $row->token;

                if (preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
                    continue;
                }

                DB::table('invitations')->where('id', $row->id)->update(['token' => hash('sha256', $token)]);
            }
        });
    }
};
