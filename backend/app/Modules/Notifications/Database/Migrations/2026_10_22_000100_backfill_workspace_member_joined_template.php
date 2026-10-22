<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The template `workspace_member_joined` needs on an existing database — without
 * it `DispatchNotification` logs and drops every «انضمّ … إلى فريقك» on
 * production while the suite, which seeds templates fresh, stays green.
 * `seedMissing()`, never `run()`: the other rows are editable from the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    public function down(): void
    {
        //
    }
};
