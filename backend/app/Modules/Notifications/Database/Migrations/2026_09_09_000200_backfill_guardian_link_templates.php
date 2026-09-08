<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 030 — the two template rows this phase adds, delivered to a database that
 * already exists.
 *
 * ⚠️ THE SIXTH TIME THIS TREE HAS NEEDED EXACTLY THIS, and the failure mode here
 * is the quiet one: `TemplateRenderer` refuses to render a missing template and
 * `DispatchNotification` LOGS rather than failing the operation that triggered
 * it. So without this migration, a student is never told a guardian is waiting on
 * them and a guardian is never told the answer — with no error anywhere, and with
 * every test green, because `tests/Pest.php` seeds the catalogue before every
 * Feature case. A green suite over a table production does not have.
 *
 * ⚠️ `seedMissing()`, NEVER `run()`. Every row is editable from `/admin`, and
 * `run()`'s `updateOrCreate` would reset every Arabic body an operator has tuned,
 * on every release. `seedMissing()` is `firstOrCreate` — it writes only what is
 * absent and never touches what is there.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /**
     * Deliberately empty. Deleting the rows would put the two new types back into
     * the silently-dropped state this migration exists to leave, and
     * `NotificationTemplateCoverageTest` refuses a type with no template — so a
     * rollback that removed them would red the build it was rolling back to.
     */
    public function down(): void {}
};
