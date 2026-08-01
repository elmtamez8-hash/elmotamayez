<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Platform-level role, distinct from workspace roles (spatie runs in team
            // mode) and from the is_super_admin flag. Null = account created through
            // the existing academy-signup path, which this feature leaves untouched.
            $table->string('platform_role')->nullable()->index();
            $table->string('phone')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('grade_level_slug')->nullable();
            $table->boolean('registered_by_parent')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'platform_role',
                'phone',
                'country',
                'grade_level_slug',
                'registered_by_parent',
            ]);
        });
    }
};
