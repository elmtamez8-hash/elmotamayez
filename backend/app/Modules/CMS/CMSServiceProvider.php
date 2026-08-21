<?php

declare(strict_types=1);

namespace App\Modules\CMS;

use App\Modules\CMS\Support\CmsPersonalData;
use App\Shared\Modules\Module;

class CMSServiceProvider extends Module
{
    protected string $name = 'CMS';

    public function register(): void
    {
        parent::register();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        |
        | ⚠️ AND THIS PROVIDER HAD NO `register()` AT ALL until now. A module with
        | nothing to bind still owns personal columns — `cms_articles.author_id` —
        | and "it had no register method" is exactly the kind of reason a module
        | gets skipped and its data goes undeclared.
        */
        $this->app->tag([CmsPersonalData::class], 'compliance.personal_data');
    }
}
