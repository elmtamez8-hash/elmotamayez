<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `messages` onto every ACCEPTED guardian relation (owner decision 2026-09-28).
 *
 * «Write to my child's teacher» is new, and the owner chose to grant it to every
 * guardian the student has already accepted, rather than make each family tick
 * it. The student removes it on /family like any other permission. Pending and
 * revoked rows are left alone: a link nobody accepted grants nothing.
 *
 * Through `DB::table()`, never the model — a migration speaks the schema of its
 * own date — and walked by id so a large table is not held in memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parent_student_relations')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $permissions = json_decode((string) $row->permissions, true);
                    $permissions = is_array($permissions) ? $permissions : [];

                    if (in_array('messages', $permissions, true)) {
                        continue;
                    }

                    $permissions[] = 'messages';

                    DB::table('parent_student_relations')
                        ->where('id', $row->id)
                        ->update(['permissions' => json_encode(array_values($permissions))]);
                }
            });
    }

    public function down(): void
    {
        // Left in place: a student may have kept or removed it since, and a
        // rollback cannot tell the backfill from their choice.
    }
};
